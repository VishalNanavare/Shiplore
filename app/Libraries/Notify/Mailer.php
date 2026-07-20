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
    public function __construct(private array $cfg) {}

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
        if ($to === '' || ! $this->configured()) {
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
            $crypto   = $this->str('encryption') ?: 'tls';
            $settings += [
                'SMTPHost'    => $this->str('host'),
                'SMTPPort'    => (int) ($this->cfg['port'] ?? 587),
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

            log_message('error', "Mailer: {$protocol} send to {$to} failed. " . $email->printDebugger(['headers']));
        } catch (Throwable $e) {
            log_message('error', 'Mailer: ' . $e->getMessage());
        }

        return false;
    }

    private function str(string $key): string
    {
        return trim((string) ($this->cfg[$key] ?? ''));
    }
}
