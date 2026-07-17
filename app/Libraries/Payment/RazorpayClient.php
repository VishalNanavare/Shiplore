<?php

declare(strict_types=1);

namespace App\Libraries\Payment;

use Throwable;

/**
 * RazorpayClient — the live Razorpay Orders + signature-verification used by the
 * storefront checkout (the gateway driver classes only hold config; this does the
 * actual API work). Reads the active 'razorpay' gateway_accounts config; when no
 * keys are configured it reports unavailable and checkout falls back to COD.
 *
 * Flow: createOrder() → Razorpay Checkout JS (browser) → verifySignature() of the
 * returned payment → mark the order paid.
 */
final class RazorpayClient
{
    /** @param array<string,mixed> $config razorpay gateway config (key_id, key_secret) */
    public function __construct(private array $config) {}

    public function configured(): bool
    {
        return $this->keyId() !== '' && $this->keySecret() !== '';
    }

    public function keyId(): string
    {
        return trim((string) ($this->config['key_id'] ?? ''));
    }

    private function keySecret(): string
    {
        return trim((string) ($this->config['key_secret'] ?? ''));
    }

    /**
     * Create a Razorpay order. Amount is in the major unit (rupees) and converted
     * to paise here. Returns the Razorpay order array (with 'id') or null.
     *
     * @return array<string,mixed>|null
     */
    public function createOrder(float $amount, string $receipt): ?array
    {
        if (! $this->configured()) {
            return null;
        }
        try {
            $res = \Config\Services::curlrequest(['timeout' => 15, 'http_errors' => false])
                ->post('https://api.razorpay.com/v1/orders', [
                    'auth' => [$this->keyId(), $this->keySecret()],
                    'json' => [
                        'amount'          => (int) round($amount * 100), // paise
                        'currency'        => 'INR',
                        'receipt'         => mb_substr($receipt, 0, 40),
                        'payment_capture' => 1,
                    ],
                ]);
            $body = json_decode((string) $res->getBody(), true);

            return is_array($body) && ! empty($body['id']) ? $body : null;
        } catch (Throwable $e) {
            log_message('error', 'RazorpayClient createOrder failed: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Verify the checkout callback signature: HMAC-SHA256(order_id|payment_id) with
     * the key secret must equal the signature Razorpay returned. Constant-time.
     */
    public function verifySignature(string $orderId, string $paymentId, string $signature): bool
    {
        if (! $this->configured() || $orderId === '' || $paymentId === '' || $signature === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, $this->keySecret());

        return hash_equals($expected, $signature);
    }
}
