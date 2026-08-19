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

    /** A connector that connects and speaks the given greeting line. */
    private function greeting(string $line, ?callable $onTarget = null): callable
    {
        return static function (string $target, int $port, int $timeout) use ($line, $onTarget): array {
            if ($onTarget !== null) {
                $refusal = $onTarget($target);
                if ($refusal !== null) {
                    return $refusal;
                }
            }
            $s = fopen('php://memory', 'r+');
            fwrite($s, $line);
            rewind($s);

            return [$s, 0, ''];
        };
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
        $out = $this->mailer([], $this->greeting("220 smtp.example.com ESMTP ready\r\n"))->diagnose();

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
        $out = $this->mailer([], $this->greeting("HTTP/1.1 400 Bad Request\r\n"))->diagnose();

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
        // Plain connect succeeds; the ssl:// attempt fails.
        $connector = $this->greeting(
            "220 smtp.example.com ESMTP ready\r\n",
            static fn (string $target): ?array => str_starts_with($target, 'ssl://')
                ? [false, 0, 'SSL operation failed with code 1. certificate verify failed']
                : null,
        );

        $out = $this->mailer([], $connector)->diagnose();

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
     * printDebugger() concatenates the debug messages and THEN the raw headers, and
     * redact() caps the result at 600 characters. Asking for headers meant a real failure
     * reported ~100 characters of generic text followed by 500 characters of Date/From/
     * Message-ID — the operator's actual message was cut off mid-word in "Mime-Version".
     * Passing an empty include list returns the messages alone (Email.php: the <pre> block
     * is only appended when $rawData is non-empty).
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
}
