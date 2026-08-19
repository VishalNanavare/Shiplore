<?php

declare(strict_types=1);

use App\Libraries\Notify\Mailer;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Mailer::diagnose() — find out WHY an SMTP send failed, when CodeIgniter will not say.
 *
 * The framework throws the real reason away. system/Email/Email.php:1910 calls fsockopen()
 * WITHOUT @-suppression, so a failed connection raises a PHP warning; CodeIgniter's error
 * handler turns that into an ErrorException, which means the very next line —
 * setErrorMessage(lang('Email.SMTPError', [$errno . ' ' . $errstr])) at :1918 — never runs.
 * spoolEmail() catches it at :1709, log_message()s it, and sets only the generic
 * "Unable to send email using SMTP. Your server might not be configured to send mail using
 * this method." So the errno/errstr exists only in writable/logs, which is exactly what an
 * operator on shared hosting cannot read. That is not a hypothetical: it is the message a
 * live send produced here, with no transcript and no error code in it at all.
 *
 * diagnose() opens its own socket, with its own @-suppressed error capture, and reports
 * what it finds. It answers the one question that decides the next step: can this box
 * reach the SMTP host at all, or does it need to stop using the network and hand off to a
 * local MTA instead.
 *
 * The socket is injected so these tests exercise real branching without touching the
 * network — a test that needs DNS is a test that fails in CI for reasons unrelated to the
 * code.
 */
final class MailerDiagnoseTest extends CIUnitTestCase
{
    /** A connector that reports a failure, the way fsockopen does. */
    private function failing(int $errno, string $errstr): callable
    {
        return static fn (string $target, int $port, int $timeout): array => [false, $errno, $errstr];
    }

    /**
     * A connector scripted per TARGET.
     *
     * Plain TCP and ssl:// must be answerable independently, because they are different
     * questions and the probe's whole job is to tell them apart. $plan keys: 'plain' and
     * 'ssl'. Each value is ['fail', errno, errstr] or ['say', "220 ...\r\n"] — and "say
     * nothing" is ['say', ''], which is precisely what an implicit-TLS server does on a
     * plain socket.
     *
     * @param array<string,array{0:string,1:mixed,2?:string}> $plan
     */
    private function scripted(array $plan): callable
    {
        return static function (string $target, int $port, int $timeout) use ($plan): array {
            $key  = str_starts_with($target, 'ssl://') ? 'ssl' : 'plain';
            $step = $plan[$key] ?? ['say', "220 default ESMTP\r\n"];

            if ($step[0] === 'fail') {
                return [false, (int) $step[1], (string) ($step[2] ?? '')];
            }

            $s = fopen('php://memory', 'r+');
            fwrite($s, (string) $step[1]);
            rewind($s);

            return [$s, 0, ''];
        };
    }

    /** Shorthand: both sockets answer with the same greeting. */
    private function greeting(string $line): callable
    {
        return $this->scripted(['plain' => ['say', $line], 'ssl' => ['say', $line]]);
    }

    /** @param array<string,mixed> $over */
    private function mailer(array $over = [], ?callable $connector = null): Mailer
    {
        return new Mailer($over + [
            'protocol' => 'smtp', 'host' => 'smtp.example.com', 'port' => '465',
            'username' => 'user@example.com', 'password' => 'sup3r-s3cret',
            'encryption' => 'ssl', 'from_email' => 'user@example.com', 'from_name' => 'Test',
        ], $connector);
    }

    // ------------------------------------------------------- nothing to probe

    /** sendmail hands off to a local binary. There is no socket, so there is nothing to test. */
    public function testSendmailHasNothingToProbe(): void
    {
        $this->assertSame('', $this->mailer(['protocol' => 'sendmail'], $this->failing(111, 'refused'))->diagnose());
    }

    public function testAnEmptyHostHasNothingToProbe(): void
    {
        $this->assertSame('', $this->mailer(['host' => ''], $this->failing(111, 'refused'))->diagnose());
    }

    // ------------------------------------------------------- the blocked case

    /**
     * THE ONE THAT MATTERS. A blocked outbound port is the single most likely cause on
     * shared hosting, and it is the case CodeIgniter reports as "your server might not be
     * configured", which sends the operator to look at settings that are perfectly fine.
     */
    public function testATimedOutConnectionIsNamedAndPointsAtSendmail(): void
    {
        $out = $this->mailer([], $this->failing(110, 'Connection timed out'))->diagnose();

        $this->assertStringContainsString('smtp.example.com', $out, 'name the host that could not be reached');
        $this->assertStringContainsString('465', $out, 'and the port — the port is the thing that is blocked');
        $this->assertStringContainsString('Connection timed out', $out, 'the real errstr the framework discarded');
        $this->assertStringContainsStringIgnoringCase('sendmail', $out, 'and what to do about it');
    }

    public function testARefusedConnectionIsReported(): void
    {
        $out = $this->mailer([], $this->failing(111, 'Connection refused'))->diagnose();

        $this->assertStringContainsString('Connection refused', $out);
    }

    /**
     * DNS is a different problem with a different fix, so it must read differently.
     * Telling someone to switch to sendmail when the hostname is simply misspelled
     * wastes the same afternoon the generic message already wasted.
     */
    public function testADnsFailureIsDistinguishedFromABlockedPort(): void
    {
        $out = $this->mailer([], $this->failing(0, 'php_network_getaddresses: getaddrinfo failed: Name or service not known'))->diagnose();

        $this->assertStringContainsStringIgnoringCase('host name', $out, 'a DNS failure must read as a name problem, not a firewall one');
    }

    // ------------------------------------------------------- reachable cases

    /** Reachable and speaking SMTP means the transport is fine and the fault is later. */
    public function testAReachableServerSaysTheFaultIsLaterInTheSession(): void
    {
        // Port 465, so the greeting lives inside the TLS channel — the plain socket is
        // silent, which is correct and must not be read as a fault.
        $out = $this->mailer([], $this->scripted([
            'plain' => ['say', ''],
            'ssl'   => ['say', "220 smtp.example.com ESMTP ready\r\n"],
        ]))->diagnose();

        $this->assertStringContainsStringIgnoringCase('reachable', $out);
        // 'App Password', not 'password'. The loose form is satisfied by any passing
        // mention — a mutation run kept it green with the actual advice deleted, because
        // the sentence still ended "not the account password".
        $this->assertStringContainsString('App Password', $out, 'if the socket is fine, the Gmail App Password is the next suspect');
    }

    /**
     * Something answered, but not an SMTP server.
     *
     * This is the interception case the Mailer docblock already warns about: a host that
     * transparently redirects outbound 25/465 to its own service. The connection succeeds,
     * which makes every reachability check pass, and the session then fails for reasons
     * that look like a credential problem. The greeting is what separates the two.
     */
    public function testAnAnswerThatIsNotAnSmtpGreetingIsCalledOut(): void
    {
        // Port 587: STARTTLS, so the server DOES greet in plaintext and a non-220 answer
        // there is genuinely wrong. On 465 the same silence is correct — see below.
        $out = $this->mailer(
            ['port' => '587', 'encryption' => 'tls'],
            $this->scripted(['plain' => ['say', "HTTP/1.1 400 Bad Request\r\n"]]),
        )->diagnose();

        $this->assertStringContainsStringIgnoringCase('did not answer', $out);
        $this->assertStringContainsStringIgnoringCase('intercept', $out, 'a non-SMTP answer on an SMTP port means something is in the middle');
    }

    /**
     * Plain TCP reaches the port but the TLS handshake does not complete.
     *
     * Worth separating because the remedy is different again — it points at the encryption
     * setting or a certificate, not at a firewall. The probe tries plain TCP first
     * precisely so these two can be told apart.
     */
    public function testATlsFailureOnAReachablePortIsDistinguished(): void
    {
        // Plain connect succeeds and stays silent, as a 465 server should; ssl:// fails.
        $out = $this->mailer([], $this->scripted([
            'plain' => ['say', ''],
            'ssl'   => ['fail', 0, 'SSL operation failed with code 1. certificate verify failed'],
        ]))->diagnose();

        $this->assertStringContainsStringIgnoringCase('TLS', $out);
        $this->assertStringContainsString('certificate verify failed', $out);
    }

    // ------------------------------------------------------- safety

    /** The password must never appear in something written for a browser. */
    public function testTheDiagnosisNeverCarriesTheCredential(): void
    {
        $out = $this->mailer([], $this->failing(110, 'Connection timed out for sup3r-s3cret'))->diagnose();

        $this->assertStringNotContainsString('sup3r-s3cret', $out);
    }

    /** diagnose() is a diagnostic, not another way to fail a request. */
    public function testAThrowingConnectorIsContained(): void
    {
        $out = $this->mailer([], static function (): array { throw new RuntimeException('boom'); })->diagnose();

        $this->assertIsString($out);
    }

    /**
     * The probe must NOT run as part of send().
     *
     * send() is on the password-reset path and the notification worker. Probing there
     * would add two socket timeouts to every failed send on the exact paths that must stay
     * fast and fail-safe. The admin screens call diagnose() explicitly instead.
     */
    public function testSendDoesNotProbe(): void
    {
        $probed = false;
        $connector = static function () use (&$probed): array {
            $probed = true;

            return [false, 110, 'Connection timed out'];
        };

        // Fails early on the empty recipient; the point is that no probe was attempted.
        $this->mailer([], $connector)->send('', 's', 'b');

        $this->assertFalse($probed, 'send() must never open a diagnostic socket');
    }

    /**
     * The raw header dump is not part of the reported error.
     *
     * Asking for headers meant a real failure reported ~100 characters of diagnosis
     * followed by 500 characters of Date/From/Message-ID, cut off mid-word in
     * "Mime-Version". An empty include list returns the messages alone (Email.php: the
     * <pre> block is appended only when $rawData is non-empty).
     *
     * READABILITY, not capacity. This was first justified by the claim that the header
     * dump "consumed the 600-character budget" — which is impossible. printDebugger()
     * returns messages FIRST and the <pre> block LAST, and redact() ends with
     * mb_substr($clean, 0, 600), a head-keeping cut: if the messages are under 600
     * characters every one of them survives, and if they are over it the headers
     * contribute nothing. Appended headers can never displace a debug message. Recorded
     * because a wrong reason attached to a right change is how the wrong reason survives.
     *
     * A source assertion: intercepting printDebugger's arguments needs a fake CodeIgniter
     * Email, and the behaviour being pinned is which argument is passed.
     */
    public function testTheReportedErrorDoesNotIncludeTheRawHeaderDump(): void
    {
        $src = (string) file_get_contents(APPPATH . 'Libraries/Notify/Mailer.php');

        $this->assertStringContainsString('printDebugger([])', $src);
        $this->assertStringNotContainsString("printDebugger(['headers'])", $src);
    }

    // ------------------------------------------- implicit TLS vs STARTTLS

    /**
     * THE BUG THIS FIXES. Silence on a plain socket to port 465 is CORRECT.
     *
     * 465 is implicit TLS (RFC 8314): the server sends nothing until the client completes
     * a TLS handshake, and the 220 greeting only arrives afterwards, inside the encrypted
     * channel. Reading a plaintext greeting there and calling the silence "interception"
     * is a false positive — and it shipped. A live probe told the operator that something
     * on their network was hijacking port 465 and to abandon SMTP for sendmail, when what
     * had actually happened was Gmail behaving exactly as specified.
     *
     * The evidence in that same message contradicted its own conclusion: the TCP
     * connection SUCCEEDED, which rules out the blocked port the advice was aimed at. A
     * diagnostic that confidently names the wrong cause is worse than the generic string
     * it replaced, because the generic string at least did not send anyone anywhere.
     */
    public function testSilenceOnAnImplicitTlsPortIsNotInterception(): void
    {
        $out = $this->mailer([], $this->scripted([
            'plain' => ['say', ''],
            'ssl'   => ['say', "220 smtp.gmail.com ESMTP ready\r\n"],
        ]))->diagnose();

        $this->assertStringNotContainsStringIgnoringCase('intercept', $out, 'a silent plain socket on 465 is the specification, not an attack');
        $this->assertStringNotContainsStringIgnoringCase('sendmail', $out, 'and it must not recommend abandoning a transport that works');
        $this->assertStringContainsStringIgnoringCase('reachable', $out);
    }

    /** On 465 the greeting must be read THROUGH TLS, the only place it exists. */
    public function testTheGreetingIsReadThroughTlsOnAnImplicitTlsPort(): void
    {
        $out = $this->mailer([], $this->scripted([
            'plain' => ['say', ''],
            'ssl'   => ['say', "220 mx.unmistakable-banner.example ESMTP\r\n"],
        ]))->diagnose();

        $this->assertStringContainsString('unmistakable-banner', $out, 'the reported greeting must come from the TLS channel');
    }

    /** Garbage over TLS on 465 IS interception — the check moves, it does not vanish. */
    public function testANonSmtpAnswerOverTlsIsStillInterception(): void
    {
        $out = $this->mailer([], $this->scripted([
            'plain' => ['say', ''],
            'ssl'   => ['say', "HTTP/1.1 200 OK\r\n"],
        ]))->diagnose();

        $this->assertStringContainsStringIgnoringCase('intercept', $out);
    }

    /**
     * Port 465 means implicit TLS even when the encryption field says otherwise.
     *
     * Both halves of that condition need their own case. Every other test here uses 465
     * AND "ssl" together, so either half alone satisfies them and a mutation run kept both
     * "465 no longer counts" and "ssl no longer counts" alive. The port is authoritative:
     * a saved 465/tls pair is exactly the contradiction hasCryptoMismatch() exists to
     * catch, and diagnose() must not read the plain socket just because a field is wrong.
     */
    public function testPort465IsImplicitTlsEvenWhenEncryptionSaysTls(): void
    {
        $out = $this->mailer(['port' => '465', 'encryption' => 'tls'], $this->scripted([
            'plain' => ['say', ''],
            'ssl'   => ['say', "220 smtp.gmail.com ESMTP ready\r\n"],
        ]))->diagnose();

        $this->assertStringNotContainsStringIgnoringCase('intercept', $out);
        $this->assertStringContainsStringIgnoringCase('reachable', $out);
    }

    /** And "ssl" means implicit TLS on a non-standard port, where 465 cannot carry it. */
    public function testSslEncryptionIsImplicitTlsOnANonStandardPort(): void
    {
        $out = $this->mailer(['port' => '2465', 'encryption' => 'ssl'], $this->scripted([
            'plain' => ['say', ''],
            'ssl'   => ['say', "220 mx.custom-port-banner.example ESMTP\r\n"],
        ]))->diagnose();

        $this->assertStringContainsString('custom-port-banner', $out, 'the banner must come from the TLS channel on any ssl port');
        $this->assertStringContainsString('2465', $out);
    }

    /** 587 is STARTTLS: the server greets in plaintext, so silence there IS suspicious. */
    public function testSilenceOnAStarttlsPortIsStillSuspicious(): void
    {
        $out = $this->mailer(
            ['port' => '587', 'encryption' => 'tls'],
            $this->scripted(['plain' => ['say', '']]),
        )->diagnose();

        $this->assertStringContainsStringIgnoringCase('did not answer', $out);
    }

    /** A normal 587 greeting reads as reachable, with no TLS probe needed. */
    public function testAPlaintextGreetingOn587ReadsAsReachable(): void
    {
        $out = $this->mailer(
            ['port' => '587', 'encryption' => 'tls'],
            $this->scripted(['plain' => ['say', "220 smtp.example.com ESMTP\r\n"]]),
        )->diagnose();

        $this->assertStringContainsStringIgnoringCase('reachable', $out);
    }

    /**
     * A silent socket must not hang the admin page.
     *
     * fsockopen's timeout covers the CONNECT only. The read that follows falls back to
     * default_socket_timeout — 60 seconds on a stock PHP — so a server that connects and
     * then says nothing would park the request for a minute. On an implicit-TLS port
     * silence is the NORMAL case, so this is not a rare path; it is the common one.
     */
    public function testTheGreetingReadHasItsOwnTimeout(): void
    {
        $src = (string) file_get_contents(APPPATH . 'Libraries/Notify/Mailer.php');

        $this->assertStringContainsString('stream_set_timeout', $src, 'the greeting read must be bounded, or a silent socket hangs the page');
    }
}
