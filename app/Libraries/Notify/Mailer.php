<?php

declare(strict_types=1);

namespace App\Libraries\Notify;

use Throwable;

/**
 * Mailer — sends transactional email over the SMTP account configured in the
 * admin Integrations panel (integration_accounts, provider "smtp"). It builds a
 * fully-initialised CodeIgniter Email instance per send from that saved config,
 * so the Gmail/SMTP credentials entered in the UI are actually used.
 *
 * Config\Email is intentionally left at framework defaults (protocol "mail");
 * the source of truth is the saved integration, injected here by the service
 * factory (Config\Services::mailer()).
 *
 * Never throws — returns false when SMTP is not configured or the send fails, so
 * callers (password reset, the notification worker, the "Test" button) stay
 * fail-safe.
 *
 * @see App\Controllers\Admin\IntegrationController  where the config is saved
 */
final class Mailer
{
    /**
     * @param array<string,mixed> $cfg saved config:
     *        protocol(smtp|sendmail|mail), host, port, username, password,
     *        encryption(tls|ssl|none), from_email, from_name, sendmail_path
     *
     *        protocol=smtp opens a network socket to `host`. On servers where
     *        outbound SMTP is intercepted/redirected (the connection comes back
     *        holding the local box's own TLS certificate), use sendmail instead:
     *        it pipes to the local MTA and never touches the network.
     */
    /**
     * Why the last send failed, safe to show an operator.
     *
     * send() returns only a bool, so every failure used to end at "see writable/logs".
     * On a shared host an operator often cannot read those, which turns a one-field
     * misconfiguration into a support round-trip — that is exactly how a live email
     * outage here stayed undiagnosed. The Test button now prints this instead.
     *
     * REDACTED before it is stored: CodeIgniter's SMTP debugger echoes the session
     * transcript, which contains the base64 AUTH exchange, and the config carries the
     * password. Neither may reach a browser.
     */
    private string $lastError = '';

    public function __construct(private array $cfg) {}

    public function lastError(): string
    {
        return $this->lastError;
    }

    /**
     * The encryption the PORT implies, whatever is configured.
     *
     * Exposed so the admin screen can warn before a send is attempted: a 465/tls pair
     * fails as a silent timeout with no protocol error to report, which is far harder to
     * read than being told the two disagree.
     */
    public function impliedCrypto(): ?string
    {
        return match ((int) $this->str('port')) {
            465     => 'ssl',
            587     => 'tls',
            default => null,
        };
    }

    /** True when the saved port and encryption contradict each other. */
    public function hasCryptoMismatch(): bool
    {
        $crypto  = strtolower($this->str('encryption'));
        $implied = $this->impliedCrypto();

        return $this->protocol() === 'smtp'
            && $crypto !== '' && $crypto !== 'none'
            && $implied !== null && $implied !== $crypto;
    }

    /**
     * Strip anything that could carry a credential.
     *
     * The AUTH lines are base64, so they do not LOOK like a password and would be
     * copied into a support ticket by anyone who did not know. Matched on the SMTP
     * verbs rather than on the password value, because the value is not always echoed
     * verbatim and matching on it would miss the encoded form entirely.
     */
    private function redact(string $raw): string
    {
        // No  after the alternation: an SMTP verb is followed by a space or
        // end-of-line, and (\s.*)? covers both. An earlier version carried a literal
        // BACKSPACE byte here where  was meant — a shell heredoc ate the escape as
        // the file was written — so the pattern matched an unprintable character that
        // never occurs and the redaction silently did nothing. grep showed the line as
        // correct; od -c found it.
        $clean = preg_replace('/^(AUTH|334|235|535)(\s.*)?$/mi', '$1 [redacted]', strip_tags($raw)) ?? $raw;
        $pass  = (string) ($this->cfg['password'] ?? '');
        if ($pass !== '') {
            $clean = str_replace([$pass, base64_encode($pass)], '[redacted]', $clean);
        }

        return trim(mb_substr($clean, 0, 600));
    }


    /**
     * Transport for this send: 'smtp' (network socket to a remote server),
     * or 'sendmail'/'mail' (hand off to the local MTA on this box).
     *
     * Defaults to 'smtp' so existing saved configs keep their current behaviour.
     */
    private function protocol(): string
    {
        $p = strtolower($this->str('protocol'));

        return in_array($p, ['smtp', 'sendmail', 'mail'], true) ? $p : 'smtp';
    }

    /** True when enough config is present to attempt a send. */
    public function configured(): bool
    {
        // sendmail/mail pipe to the local MTA — no host or credentials exist to check,
        // but a From address is still mandatory or the MTA rejects the message.
        if ($this->protocol() !== 'smtp') {
            return $this->str('from_email') !== '';
        }

        return $this->str('host') !== '' && $this->str('username') !== '';
    }

    /** Send one HTML email. Returns true on success; never throws. */
    public function send(string $to, string $subject, string $htmlBody): bool
    {
        // Never refuse silently. This branch used to `return false` with no log at all,
        // which made "email isn't arriving" indistinguishable from "email was never
        // attempted" — callers only see a bool, and logger.threshold=4 discards anything
        // below error, so an unconfigured mailer left no trace anywhere.
        $this->lastError = '';

        if ($to === '') {
            $this->lastError = 'No recipient address.';
            log_message('error', 'Mailer: refusing to send — empty recipient.');

            return false;
        }
        if (! $this->configured()) {
            $this->lastError = $this->protocol() === 'smtp'
                ? 'SMTP is selected but Host or Username is empty.'
                : 'Transport "' . $this->protocol() . '" needs a From address.';
            log_message('error', sprintf(
                'Mailer: refusing to send to %s — transport "%s" is not configured (%s).',
                $to,
                $this->protocol(),
                $this->protocol() === 'smtp'
                    ? 'smtp needs a non-empty host and username'
                    : $this->protocol() . ' needs a non-empty from_email',
            ));

            return false;
        }

        $protocol = $this->protocol();
        $from     = $this->str('from_email') ?: $this->str('username');
        $name     = $this->str('from_name') ?: 'Shiplore';

        $settings = [
            'protocol'  => $protocol,
            'mailType'  => 'html',
            'charset'   => 'UTF-8',
            'fromEmail' => $from,
            'fromName'  => $name,
        ];

        if ($protocol === 'smtp') {
            $crypto = $this->str('encryption') ?: 'tls';
            // `?? 587` never fired: a blank admin Port field saves "" (not null), and
            // (int) "" is 0, so the connection went to port 0 and always failed. Treat
            // any non-positive/absent value as "use the default for this encryption".
            $port = (int) $this->str('port');
            if ($port <= 0) {
                $port = $crypto === 'ssl' ? 465 : 587;
            }

            // The PORT decides the protocol, so a contradiction is resolved in its
            // favour rather than attempted and left to time out.
            //
            // 465 is IMPLICIT TLS: the handshake happens the moment the socket opens.
            // 587 is STARTTLS: connect in plaintext, then upgrade. Pair 465 with 'tls'
            // and the client sends plaintext at a server waiting for a handshake, so the
            // connection hangs until the timeout with no protocol-level error to report
            // — a saved config that looks entirely reasonable in the admin form and
            // simply never delivers. That exact pairing was live here.
            $paired = $port === 465 ? 'ssl' : ($port === 587 ? 'tls' : $crypto);
            if ($crypto !== 'none' && $paired !== $crypto) {
                log_message('warning', sprintf(
                    'Mailer: port %d implies "%s" but "%s" was configured — using "%s".',
                    $port,
                    $paired,
                    $crypto,
                    $paired,
                ));
                $crypto = $paired;
            }
            $settings += [
                'SMTPHost'    => $this->str('host'),
                'SMTPPort'    => $port,
                'SMTPUser'    => $this->str('username'),
                'SMTPPass'    => $this->str('password'),
                'SMTPCrypto'  => $crypto === 'none' ? '' : $crypto,
                'SMTPTimeout' => 15,
            ];
        } elseif ($protocol === 'sendmail') {
            // Local binary — no socket, so nothing on the network can intercept or
            // TLS-mismatch it. Path is overridable for non-standard installs.
            $settings['mailPath'] = $this->str('sendmail_path') ?: '/usr/sbin/sendmail';
        }

        try {
            $email = service('email', null, false);
            $email->initialize($settings);
            $email->setFrom($from, $name);
            $email->setTo($to);
            $email->setSubject($subject);
            $email->setMessage($htmlBody);

            if ($email->send(false)) {
                return true;
            }

            $debug           = $email->printDebugger(['headers']);
            $this->lastError = $this->redact($debug);
            log_message('error', "Mailer: {$protocol} send to {$to} failed. " . $debug);
        } catch (Throwable $e) {
            $this->lastError = $this->redact($e->getMessage());
            log_message('error', 'Mailer: ' . $e->getMessage());
        }

        return false;
    }

    private function str(string $key): string
    {
        return trim((string) ($this->cfg[$key] ?? ''));
    }
}
