<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Libraries/Payment/PayuClient.php';
require_once __DIR__ . '/../../../app/Libraries/Money.php';

use App\Libraries\Money;
use App\Libraries\Payment\PayuClient;

/**
 * Guards the PayU signing oracle and the amount binding.
 *
 * POST /api/v1/customer/payu/hash used to sign ANY client-supplied string.
 * PayuClient::hash() is sha512($s . $salt) — salt APPENDED — which is exactly the
 * shape of PayU's forward payment hash. So a customer could mint a valid payment
 * hash for an amount of their choosing, pay 1.00 against a 10,000.00 order, and
 * PayU would hand back a genuinely-valid success response for that 1.00. payVerify()
 * then checked only the reverse hash, never the amount or the txnid, and captured.
 *
 * These tests pin the two invariants the fix relies on:
 *   1. a forward payment hash IS reproducible through hash() — i.e. the oracle was
 *      real, which is why payuHash() must inspect payment-shaped strings;
 *   2. payment-shaped strings are distinguishable from the SDK's other hashes, so
 *      the guard can be strict without breaking already-shipped apps.
 */
final class PayuHashOracleTest extends TestCase
{
    private const KEY  = 'TESTKEY123';
    private const SALT = 'TESTSALT456';

    private PayuClient $payu;

    protected function setUp(): void
    {
        $this->payu = new PayuClient(['merchant_key' => self::KEY, 'merchant_salt' => self::SALT]);
    }

    /** The whole reason payuHash() needs a guard: hash() can forge a payment hash. */
    public function testGenericHashCanForgeAForwardPaymentHash(): void
    {
        $legitimate = $this->payu->paymentHash([
            'txnid'       => 'SL1001',
            'amount'      => '1.00',
            'productinfo' => 'Order SL1001',
            'firstname'   => 'Mallory',
            'email'       => 'm@example.com',
        ]);

        // What an attacker would send as hash_string: the same fields, trailing
        // pipes for the five blanks, and no salt (the server appends it).
        $forged = $this->payu->hash(
            self::KEY . '|SL1001|1.00|Order SL1001|Mallory|m@example.com||||||||||' . '|',
        );

        $this->assertSame(
            $legitimate,
            $forged,
            'hash() must be assumed capable of producing a valid payment hash; if this ever '
            . 'stops holding, re-check whether payuHash() still needs its amount guard.',
        );
    }

    /**
     * The discriminator used by payuHash(): a payment hash string has at least 16
     * pipe-separated fields, the SDK's other hashes have far fewer.
     *
     * @dataProvider hashStrings
     */
    public function testPaymentShapeIsDistinguishable(string $hashString, bool $expectedPaymentShaped): void
    {
        $isPaymentShaped = count(explode('|', $hashString)) >= 16;

        $this->assertSame($expectedPaymentShaped, $isPaymentShaped, $hashString);
    }

    public static function hashStrings(): array
    {
        return [
            'forward payment hash' => [
                self::KEY . '|SL1001|1.00|Order SL1001|Mallory|m@example.com|||||||||||', true,
            ],
            'payment hash with udfs' => [
                self::KEY . '|SL1001|999.50|Order|Name|e@x.com|u1|u2|u3|u4|u5|||||', true,
            ],
            // These carry no amount, so signing them cannot move money — they must
            // keep working or the shipped apps' generateHash callback breaks.
            'sdk payment_related_details' => [self::KEY . '|payment_related_details_for_mobile_sdk|user123|', false],
            'sdk vas_for_mobile_sdk'      => [self::KEY . '|vas_for_mobile_sdk|default|', false],
            'sdk get_user_cards'          => [self::KEY . '|get_user_cards|user123|', false],
        ];
    }

    /** A tampered response must not verify — the reverse hash covers amount and txnid. */
    public function testVerifyResponseRejectsTamperedAmount(): void
    {
        $resp = [
            'status' => 'success', 'txnid' => 'SL1001', 'amount' => '10000.00',
            'productinfo' => 'Order SL1001', 'firstname' => 'Alice', 'email' => 'a@example.com',
        ];
        $resp['hash'] = hash('sha512', implode('|', [
            self::SALT, 'success', '', '', '', '', '', '', '', '', '', '',
            'a@example.com', 'Alice', 'Order SL1001', '10000.00', 'SL1001', self::KEY,
        ]));

        $this->assertTrue($this->payu->verifyResponse($resp), 'genuine response should verify');

        $tampered           = $resp;
        $tampered['amount'] = '1.00';
        $this->assertFalse($this->payu->verifyResponse($tampered), 'amount tampering must break the hash');

        $replayed          = $resp;
        $replayed['txnid'] = 'SL9999';
        $this->assertFalse($this->payu->verifyResponse($replayed), 'txnid swap must break the hash');
    }

    public function testVerifyResponseRejectsMissingHash(): void
    {
        $this->assertFalse($this->payu->verifyResponse(['status' => 'success', 'txnid' => 'SL1001']));
    }

    /**
     * Amounts are compared as Money (exact integer units), never as floats. These
     * are the pairs the callback guard has to get right.
     */
    public function testAmountComparisonIsExact(): void
    {
        $this->assertTrue(Money::of('10000.00')->equals(Money::of('10000')));
        $this->assertTrue(Money::of('1.10')->equals(Money::of('1.1000')));
        $this->assertFalse(Money::of('1.00')->equals(Money::of('10000.00')));
        // The classic float trap: 0.1 + 0.2 != 0.3 in binary floating point.
        $this->assertTrue(Money::of('0.1')->add(Money::of('0.2'))->equals(Money::of('0.3')));
    }

    /** The claimed amount is attacker-controlled, so malformed input must throw, not coerce. */
    public function testMalformedAmountIsRejectedRatherThanCoerced(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of('1.00 OR 1=1');
    }
}
