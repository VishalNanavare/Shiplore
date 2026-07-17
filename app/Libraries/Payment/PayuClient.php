<?php

declare(strict_types=1);

namespace App\Libraries\Payment;

/**
 * PayuClient — PayU (India) hashing used by the customer-app checkout. PayU's
 * mobile SDK (CheckoutPro) opens the hosted payment screen; the SECRET SALT must
 * never leave the server, so the app asks the backend to sign each hash string.
 *
 * Flow: payInit() returns the payment params + forward hash → app opens
 * CheckoutPro (and calls payuHash() for any additional SDK hashes) → on success
 * the app posts the PayU response to payVerify(), which checks the reverse hash
 * (HMAC-style, never trust the client) before marking the order paid.
 *
 * @see app/Controllers/Api/V1/CustomerApiController.php (payInit/payuHash/payVerify)
 */
final class PayuClient
{
    /** @param array<string,mixed> $config payu gateway config (merchant_key, merchant_salt) */
    public function __construct(private array $config) {}

    public function configured(): bool
    {
        return $this->key() !== '' && $this->salt() !== '';
    }

    public function key(): string
    {
        return trim((string) ($this->config['merchant_key'] ?? ''));
    }

    private function salt(): string
    {
        return trim((string) ($this->config['merchant_salt'] ?? ''));
    }

    /** Demo/sandbox merchant keys run against PayU's test gateway (SDK env = 1). */
    public function isTest(): bool
    {
        return ! empty($this->config['test']) || str_contains(strtoupper($this->key()), 'DEMO') || str_contains(strtoupper($this->key()), 'TEST');
    }

    /**
     * Sign an arbitrary PayU hash string (powers the CheckoutPro SDK's generateHash
     * callback for every hash name). The SDK supplies the exact string to sign.
     */
    public function hash(string $hashString): string
    {
        return $this->configured() ? hash('sha512', $hashString . $this->salt()) : '';
    }

    /**
     * Forward payment-request hash:
     *   sha512(key|txnid|amount|productinfo|firstname|email|udf1|…|udf5||||||salt)
     * @param array{txnid:string,amount:string,productinfo:string,firstname:string,email:string,udf1?:string,udf2?:string,udf3?:string,udf4?:string,udf5?:string} $p
     */
    public function paymentHash(array $p): string
    {
        if (! $this->configured()) {
            return '';
        }
        $s = implode('|', [
            $this->key(), $p['txnid'], $p['amount'], $p['productinfo'], $p['firstname'], $p['email'],
            $p['udf1'] ?? '', $p['udf2'] ?? '', $p['udf3'] ?? '', $p['udf4'] ?? '', $p['udf5'] ?? '',
            '', '', '', '', '', $this->salt(),
        ]);

        return hash('sha512', $s);
    }

    /**
     * Verify a PayU checkout response — recompute the reverse hash and constant-time
     * compare it with the one PayU returned:
     *   sha512(salt|status|||||udf5|udf4|udf3|udf2|udf1|email|firstname|productinfo|amount|txnid|key)
     * @param array<string,mixed> $r the full PayU response payload
     */
    public function verifyResponse(array $r): bool
    {
        if (! $this->configured()) {
            return false;
        }
        $received = strtolower(trim((string) ($r['hash'] ?? '')));
        if ($received === '') {
            return false;
        }
        $s = implode('|', [
            $this->salt(), (string) ($r['status'] ?? ''),
            '', '', '', '', '',
            (string) ($r['udf5'] ?? ''), (string) ($r['udf4'] ?? ''), (string) ($r['udf3'] ?? ''), (string) ($r['udf2'] ?? ''), (string) ($r['udf1'] ?? ''),
            (string) ($r['email'] ?? ''), (string) ($r['firstname'] ?? ''), (string) ($r['productinfo'] ?? ''), (string) ($r['amount'] ?? ''), (string) ($r['txnid'] ?? ''), $this->key(),
        ]);

        return hash_equals(hash('sha512', $s), $received);
    }
}
