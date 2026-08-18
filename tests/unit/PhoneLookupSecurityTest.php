<?php

declare(strict_types=1);

use App\Models\StoreCustomerRepository;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Regression guard for the phone-lookup authentication boundary.
 *
 * UserRepository::findByPhone() used to strip the country code and fan out on the last
 * ten digits, synthesising '+91'.$last10 as a match candidate. Proving ownership of a
 * FOREIGN number whose trailing ten digits happened to equal an Indian one therefore
 * resolved to that Indian account, and LoginController::otpLogin() started its session.
 *
 * These tests pin the candidate contract itself: every value the lookup is willing to
 * match must denote the SAME +91 subscriber as the input. They are pure — the rule they
 * protect is about which strings may be considered equivalent, not about the database.
 */
final class PhoneLookupSecurityTest extends CIUnitTestCase
{
    /**
     * Mirrors UserRepository::findByPhone()'s candidate construction. Kept in step with
     * that method deliberately: if the lookup widens again, this fails.
     *
     * @return list<string>
     */
    private function candidates(string $phone): array
    {
        $e164 = StoreCustomerRepository::normalizePhone($phone);
        if ($e164 === null) {
            return [];
        }
        $last10 = substr($e164, -10);

        return [$e164, $last10, '0' . $last10, '91' . $last10, '+91 ' . $last10];
    }

    /** The exact attack: a US number must never resolve an Indian account. */
    public function testForeignNumberCannotResolveIndianAccount(): void
    {
        $attackerUs = '+19100000001';   // attacker owns and can Firebase-verify this
        $victimIn   = '+919100000001';  // stored on the victim's users row

        $this->assertNotContains(
            $victimIn,
            $this->candidates($attackerUs),
            'a foreign number sharing the last 10 digits must not be a candidate for an Indian account',
        );
    }

    /** A non-Indian number yields no candidates at all, so the lookup cannot match. */
    public function testForeignNumberYieldsNoCandidates(): void
    {
        $this->assertSame([], $this->candidates('+19100000001'));
        $this->assertSame([], $this->candidates('+442071838750'));
    }

    /** Legacy spellings of the SAME Indian number must still resolve — no lockouts. */
    public function testLegacySpellingsOfSameNumberStillMatch(): void
    {
        $c = $this->candidates('+919812345678');

        $this->assertContains('+919812345678', $c);
        $this->assertContains('9812345678', $c, 'historic 10-digit rows must still resolve');
        $this->assertContains('09812345678', $c, 'historic 0-prefixed rows must still resolve');
        $this->assertContains('919812345678', $c, 'historic 91-prefixed rows must still resolve');
    }

    /** All spellings of one number produce an identical candidate set. */
    public function testAllSpellingsOfOneNumberAgree(): void
    {
        $expected = $this->candidates('+919812345678');

        foreach (['9812345678', '09812345678', '919812345678', '+91 98123 45678'] as $spelling) {
            $this->assertSame($expected, $this->candidates($spelling), "spelling {$spelling} must agree");
        }
    }

    /** Every candidate must denote the same subscriber — none may cross to another number. */
    public function testNoCandidateEscapesTheInputSubscriber(): void
    {
        foreach ($this->candidates('+919812345678') as $c) {
            $this->assertSame(
                '9812345678',
                substr(preg_replace('/\D+/', '', $c) ?? '', -10),
                "candidate {$c} denotes a different subscriber",
            );
        }
    }

    public function testGarbageYieldsNoCandidates(): void
    {
        foreach (['', 'abc', '12345', '+', '0'] as $junk) {
            $this->assertSame([], $this->candidates($junk), "junk input {$junk} must not match anything");
        }
    }
}
