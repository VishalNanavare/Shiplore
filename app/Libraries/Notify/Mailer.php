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

    /**
     * @param callable|null $connector opens one socket, for diagnose() only.
     *        Signature: fn(string $target, int $port, int $timeout): array{0:resource|false,1:int,2:string}
     *        — the resource (or false), then errno and errstr as fsockopen reports them.
     *        Injected so the probe's branching is testable without a network: a test that
     *        needs working DNS is a test that fails for reasons unrelated to this code.
     *        Production passes nothing and gets the real socket.
     */
    public function __construct(private array $cfg, private $connector = null) {}

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
     * How long a single probe socket may take, in seconds.
     *
     * Two probes run at worst, so this bounds the admin page at ~16s. Long enough that a
     * silently-dropped packet (the signature of a firewall that blackholes rather than
     * refuses) reaches its timeout instead of being reported as something else; short
     * enough that the page still returns.
     */
    private const PROBE_TIMEOUT = 8;

    /**
     * Find out whether this box can reach the configured SMTP server at all.
     *
     * CodeIgniter discards the answer. system/Email/Email.php:1910 calls fsockopen()
     * without @-suppression, so a failed connection raises a PHP warning that the error
     * handler converts into an ErrorException — and the next line, which would have
     * recorded errno/errstr via setErrorMessage(), never executes. spoolEmail() catches it
     * at :1709, logs it, and reports only "Unable to send email using SMTP. Your server
     * might not be configured to send mail using this method." That string sends an
     * operator to check settings that are correct, while the real reason sits in
     * writable/logs where shared hosting will not let them read it.
     *
     * So we open our own socket and keep what we learn. Plain TCP FIRST, deliberately: it
     * is the only way to separate "this host blocks outbound SMTP" (switch to sendmail)
     * from "the port is open but TLS fails" (fix the encryption setting or a certificate).
     * Probing straight through ssl:// collapses both into one failure and would recommend
     * the wrong fix half the time.
     *
     * Read-only and side-effect free — it never sends anything. Called by the admin
     * screens on failure, NOT by send(): send() is on the password-reset path and the
     * notification worker, which must not pay two socket timeouts per failure.
     *
     * @return string a reason to show an operator, or '' when there is nothing to probe
     */
    public function diagnose(): string
    {
        if ($this->protocol() !== 'smtp') {
            return ''; // sendmail/mail pipe to a local binary — there is no socket to test.
        }
        $host = $this->str('host');
        if ($host === '') {
            return '';
        }

        $port = (int) $this->str('port');
        if ($port <= 0) {
            $port = strtolower($this->str('encryption')) === 'ssl' ? 465 : 587;
        }
        $where = $host . ':' . $port;

        try {
            [$sock, $errno, $errstr] = $this->connect($host, $port);

            if (! is_resource($sock)) {
                // DNS is a different fault with a different remedy. Recommending sendmail
                // for a misspelled hostname wastes the same afternoon the generic message
                // already wasted, so the two must not read alike.
                if (stripos($errstr, 'getaddrinfo') !== false || stripos($errstr, 'Name or service not known') !== false) {
                    return $this->redact(sprintf(
                        'The host name "%s" could not be resolved (%s). Check it for a typo before anything else.',
                        $host,
                        $errstr,
                    ));
                }

                return $this->redact(sprintf(
                    'Could not open a connection to %s — %s (errno %d). Nothing was rejected: the connection never'
                    . ' completed, which on shared hosting almost always means outbound SMTP is blocked.'
                    . ' Switch Transport to "sendmail" to hand the message to the local mail server instead.',
                    $where,
                    $errstr !== '' ? $errstr : 'no reason reported',
                    $errno,
                ));
            }

            // WHERE the greeting lives depends on the port, and getting this wrong produces
            // a confident lie. 465 is IMPLICIT TLS (RFC 8314): the server says NOTHING on a
            // plain socket and sends its 220 only after the handshake, inside the encrypted
            // channel. 587 is STARTTLS: the server greets in plaintext first.
            //
            // An earlier version read the plain socket either way, so normal Gmail-on-465
            // silence was reported as "something is intercepting this port, use sendmail".
            // That shipped and reached an operator. Its own evidence contradicted it — the
            // TCP connect had SUCCEEDED, which rules out the blocked port the advice was
            // aimed at.
            $crypto      = strtolower($this->str('encryption'));
            $implicitTls = $crypto === 'ssl' || $port === 465;

            if (! $implicitTls) {
                $greeting = $this->readGreeting($sock);
                fclose($sock);

                return $this->verdict($where, $greeting);
            }

            // Plain TCP was only ever a reachability check here; nothing will be said on it.
            fclose($sock);

            [$tls, $tlsNo, $tlsErr] = $this->connect('ssl://' . $host, $port);
            if (! is_resource($tls)) {
                return $this->redact(sprintf(
                    '%s is reachable over plain TCP, but the TLS handshake failed — %s (errno %d).'
                    . ' The port is NOT blocked, so this is an encryption or certificate problem rather than a'
                    . ' firewall. A host that intercepts outbound mail presents its own certificate here, which'
                    . ' fails verification exactly like this; Transport "sendmail" avoids the network entirely.',
                    $where,
                    $tlsErr !== '' ? $tlsErr : 'no reason reported',
                    $tlsNo,
                ));
            }

            $greeting = $this->readGreeting($tls);
            fclose($tls);

            return $this->verdict($where, $greeting);
        } catch (Throwable $e) {
            // A diagnostic must never become a second failure.
            return $this->redact('The connection test could not run: ' . $e->getMessage());
        }
    }

    /**
     * Read the SMTP banner, bounded.
     *
     * fsockopen's timeout covers the CONNECT only; the read that follows falls back to
     * default_socket_timeout, 60 seconds on a stock PHP. A peer that connects and then
     * says nothing would park the admin request for a minute — and on an implicit-TLS
     * port silence is the NORMAL case, so this is the common path, not a rare one.
     *
     * @param resource $sock
     */
    private function readGreeting($sock): string
    {
        // Best-effort: a fake stream in a test is not a socket and simply declines.
        @stream_set_timeout($sock, self::PROBE_TIMEOUT);

        return trim((string) fgets($sock, 512));
    }

    /**
     * Turn a banner into an answer.
     *
     * Shared by both paths so the plaintext-greeting port and the implicit-TLS port cannot
     * drift into judging the same evidence differently.
     */
    private function verdict(string $where, string $greeting): string
    {
        // Connected and handshaked, but is this actually an SMTP server? A host that
        // transparently redirects outbound mail ports to its own service passes every
        // reachability check and then fails the session in ways that look like bad
        // credentials. The banner is the only thing that separates the two.
        if (! str_starts_with($greeting, '220')) {
            return $this->redact(sprintf(
                '%s accepted a connection but did not answer with an SMTP greeting (it said "%s").'
                . ' Something on the network is intercepting this port. Use Transport "sendmail".',
                $where,
                mb_substr($greeting, 0, 80) ?: '(nothing)',
            ));
        }

        // A 220 is necessary but NOT sufficient — it says something is speaking SMTP, not
        // that it is the server you asked for. On this host a connection to
        // smtp.gmail.com:587 was greeted by "220-...host.secureserver.net ESMTP Exim":
        // outbound mail is transparently redirected to the local MTA. Every reachability
        // check passed, so the probe reported "the network is not the problem, check your
        // App Password" — advice that can never work, because the credentials never reach
        // Gmail. The refutation was in the banner it was already printing.
        $asked  = $this->str('host');
        $banner = $this->bannerHost($greeting);

        // No `$banner !== ''` guard here on purpose: differentMailSystem() already returns
        // false for anything it cannot compare, so the extra check changed no behaviour and
        // a mutation run could not tell its removal from the original. Redundant code that
        // mutation cannot distinguish is code with no test behind it — one decision point
        // is better than two, and the decision belongs in differentMailSystem().
        if ($this->differentMailSystem($asked, $banner)) {
            return $this->redact(sprintf(
                '%s answered, but the greeting came from "%s" — a different mail system from the "%s" you'
                . ' configured. This server is redirecting outbound SMTP to its own mail host, so the username'
                . ' and password never reach %s and no credential change can fix it. The TLS handshake then'
                . ' fails because that host presents its own certificate. Switch Transport to "sendmail", which'
                . ' hands the message to the local mail server directly and never touches the network.',
                $where,
                $banner,
                $asked,
                $asked,
            ));
        }

        return $this->redact(sprintf(
            '%s is reachable and answered "%s", so the network is not the problem. The failure is later in the'
            . ' session — most often the username or password. A Gmail account needs a 16-character App Password,'
            . ' not the account password.',
            $where,
            mb_substr($greeting, 0, 80),
        ));
    }

    /**
     * The hostname an SMTP banner announces, or '' when it does not announce one.
     *
     * Banners are "220 host ESMTP ..." or the multiline "220-host ...". Anything that does
     * not yield a dotted name is returned as '' rather than guessed at: a check that
     * accuses on weak evidence is how the last false positive happened.
     */
    private function bannerHost(string $greeting): string
    {
        if (preg_match('/^220[\s-]+([A-Za-z0-9._-]+\.[A-Za-z0-9._-]+)/', $greeting, $m) !== 1) {
            return '';
        }

        return rtrim($m[1], '.');
    }

    /**
     * True when two hostnames belong to different mail systems.
     *
     * Compared on the registrable domain, NOT the full hostname, because providers
     * legitimately answer under a sibling name — smtp.office365.com banners as
     * something.outlook.office365.com, and demanding an exact match would call that an
     * attack. That is the same over-confident heuristic that produced the port-465 false
     * positive, so this errs the other way: anything it cannot compare confidently
     * (single-label names, IP literals) returns false and says nothing.
     */
    private function differentMailSystem(string $asked, string $banner): bool
    {
        $a = $this->registrableDomain($asked);
        $b = $this->registrableDomain($banner);

        return $a !== '' && $b !== '' && $a !== $b;
    }

    /** Last two labels, lowercased. '' for IP literals and single-label names. */
    private function registrableDomain(string $host): string
    {
        $host = strtolower(trim($host, " \t."));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return '';
        }

        $labels = explode('.', $host);
        if (count($labels) < 2) {
            return '';
        }

        return implode('.', array_slice($labels, -2));
    }

    /**
     * One socket attempt. @-suppressed on purpose — the whole point is to KEEP the
     * errno/errstr rather than let a warning become an exception the way it does inside
     * CodeIgniter's Email.
     *
     * @return array{0:resource|false,1:int,2:string}
     */
    private function connect(string $target, int $port): array
    {
        if ($this->connector !== null) {
            return ($this->connector)($target, $port, self::PROBE_TIMEOUT);
        }

        $errno  = 0;
        $errstr = '';
        $sock   = @fsockopen($target, $port, $errno, $errstr, self::PROBE_TIMEOUT);

        return [$sock, (int) $errno, (string) $errstr];
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

            // [] not ['headers']. The headers are never the reason a send failed, and a
            // failure that reports 100 characters of diagnosis followed by 500 characters
            // of Date/From/Message-ID — cut off mid-word in "Mime-Version", as a live one
            // did — reads as noise and buries the part that matters.
            //
            // Readability only. An earlier version of this comment claimed the dump was
            // "eating the 600-character budget", which is arithmetically impossible:
            // printDebugger() returns messages FIRST then the <pre> block, and redact()
            // cuts with mb_substr($clean, 0, 600) — a head-keeping cut. Short messages
            // always survive whole; appended headers cannot displace one character of
            // them. Worth stating, because that false claim was used to justify this line.
            $debug           = $email->printDebugger([]);
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
