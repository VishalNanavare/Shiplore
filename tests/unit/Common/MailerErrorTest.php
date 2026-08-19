<?php

declare(strict_types=1);

use App\Libraries\Notify\Mailer;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The mailer has to explain itself.
 *
 * send() returns only a bool, so every failure used to end at "see writable/logs for the
 * exact error". On shared hosting an operator frequently cannot read those, which turns a
 * one-field misconfiguration into a support round-trip — and did: a live email outage here
 * went several exchanges with nobody able to see the actual error.
 *
 * The counterweight is that the detail must never carry a credential. CodeIgniter's SMTP
 * debugger echoes the session transcript, which includes the base64 AUTH exchange — and
 * base64 does not LOOK like a password, so anyone who did not know would paste it
 * straight into a support ticket.
 */
final class MailerErrorTest extends CIUnitTestCase
{
    /** @param array<string,mixed> $over */
    private function mailer(array $over = []): Mailer
    {
        return new Mailer($over + [
            'protocol' => 'smtp', 'host' => 'smtp.example.com', 'port' => '465',
            'username' => 'user@example.com', 'password' => 'sup3r-s3cret',
            'encryption' => 'ssl', 'from_email' => 'user@example.com', 'from_name' => 'Test',
        ]);
    }

    /** Nothing has failed yet, so there is nothing to report. */
    public function testTheErrorStartsEmpty(): void
    {
        $this->assertSame('', $this->mailer()->lastError());
    }

    /** An empty recipient says so, rather than failing mutely. */
    public function testAnEmptyRecipientIsExplained(): void
    {
        $m = $this->mailer();

        $this->assertFalse($m->send('', 'subject', '<p>body</p>'));
        $this->assertStringContainsStringIgnoringCase('recipient', $m->lastError());
    }

    /**
     * The most common real cause, and the one the operator hit: SMTP selected with a
     * field missing. The message must name WHICH fields, not just "not configured".
     */
    public function testAnUnconfiguredSmtpNamesTheMissingFields(): void
    {
        $m = $this->mailer(['host' => '', 'username' => '']);

        $this->assertFalse($m->send('someone@example.com', 's', 'b'));
        $this->assertStringContainsStringIgnoringCase('host', $m->lastError());
        $this->assertStringContainsStringIgnoringCase('username', $m->lastError());
    }

    /** sendmail has no host or credentials — it needs a From address instead. */
    public function testSendmailWithoutAFromAddressIsExplained(): void
    {
        $m = $this->mailer(['protocol' => 'sendmail', 'from_email' => '', 'username' => '']);

        $this->assertFalse($m->send('someone@example.com', 's', 'b'));
        $this->assertStringContainsStringIgnoringCase('from address', $m->lastError());
    }

    /**
     * Feed the redactor a REALISTIC transcript.
     *
     * Driving this through send() proves nothing: an unreachable host fails at DNS long
     * before any AUTH exchange, so the debugger output contains no credential and the
     * redactor is never exercised. A mutation run showed exactly that — every redaction
     * mutant survived against a send()-driven test. The sanitizer is the security
     * boundary here, so it is called directly with the input it actually has to handle.
     */
    private function redactViaReflection(Mailer $m, string $raw): string
    {
        $method = new ReflectionMethod($m, 'redact');
        $method->setAccessible(true);

        return (string) $method->invoke($m, $raw);
    }

    /** A real SMTP session transcript, of the shape printDebugger() emits. */
    private function transcript(string $password): string
    {
        return "<pre>220 smtp.example.com ESMTP ready\n"
            . "EHLO localhost\n"
            . "250-AUTH LOGIN PLAIN\n"
            . 'AUTH LOGIN ' . base64_encode('user@example.com') . "\n"
            . '334 ' . base64_encode('Password:') . "\n"
            . base64_encode($password) . "\n"
            . "535 5.7.8 Username and Password not accepted\n</pre>";
    }

    /**
     * The admin Test button must PRINT the reason, not point at a logfile.
     *
     * A source assertion because the alternative is standing up the whole admin
     * integration flow to check one string. What it pins is the thing that regressed
     * conceptually: "see writable/logs" ends a diagnosis on shared hosting instead of
     * advancing it, which is exactly how a live email outage here went several rounds
     * with nobody able to see the error.
     */
    public function testTheAdminTestButtonShowsTheReason(): void
    {
        $src = (string) file_get_contents(APPPATH . 'Controllers/Admin/IntegrationController.php');

        $this->assertStringContainsString(
            '$mailer->lastError()',
            $src,
            'the failure message must include the actual reason',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/See writable\/logs for the exact error/i',
            $src,
            'pointing at a logfile the operator cannot read is what this replaced',
        );
    }

    /** THE ONE THAT MATTERS: no credential may reach the browser. */
    public function testTheEncodedPasswordIsStrippedFromATranscript(): void
    {
        $m   = $this->mailer();
        $out = $this->redactViaReflection($m, $this->transcript('sup3r-s3cret'));

        $this->assertStringNotContainsString('sup3r-s3cret', $out, 'the password must never be shown');
        $this->assertStringNotContainsString(
            base64_encode('sup3r-s3cret'),
            $out,
            'nor its base64 form — that is what an AUTH transcript actually carries, and it does not LOOK like a password to whoever pastes it into a ticket',
        );
    }

    /** The AUTH verbs themselves are masked, transcript or not. */
    public function testTheAuthExchangeIsMasked(): void
    {
        $out = $this->redactViaReflection($this->mailer(), $this->transcript('sup3r-s3cret'));

        $this->assertStringNotContainsString(base64_encode('user@example.com'), $out, 'the username line is masked too');
        $this->assertStringContainsString('[redacted]', $out);
    }

    /** The useful part survives — a masked error nobody can read helps no one. */
    public function testTheDiagnosisItselfIsPreserved(): void
    {
        $out = $this->redactViaReflection($this->mailer(), $this->transcript('sup3r-s3cret'));

        $this->assertStringContainsString('smtp.example.com', $out, 'the host is not a secret and identifies the transport');
    }

    /** Capped, so a long transcript cannot flood a flash message. */
    public function testTheErrorIsBounded(): void
    {
        $out = $this->redactViaReflection($this->mailer(), str_repeat('x', 5000));

        $this->assertLessThanOrEqual(600, mb_strlen($out));
    }

    /**
     * A second send clears the previous reason.
     *
     * The two failures are DIFFERENT on purpose: failing the same way twice cannot tell
     * a fresh reason from a stale one, which is how the first version of this test
     * passed with the reset deleted.
     */
    public function testTheErrorIsResetOnEachSend(): void
    {
        $m = $this->mailer(['host' => '', 'username' => '']);
        $m->send('someone@example.com', 's', 'b');
        $this->assertStringContainsStringIgnoringCase('host', $m->lastError());

        // Now a different failure: empty recipient. The old reason must not survive.
        $m->send('', 's', 'b');
        $this->assertStringContainsStringIgnoringCase('recipient', $m->lastError());
        $this->assertStringNotContainsStringIgnoringCase('host', $m->lastError(), 'the previous reason must be cleared');
    }
}
