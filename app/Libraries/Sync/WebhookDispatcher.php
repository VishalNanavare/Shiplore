<?php

declare(strict_types=1);

namespace App\Libraries\Sync;

use Config\Database;
use Throwable;

/**
 * WebhookDispatcher — outbound webhook event sync. `dispatch()` fans an event out
 * to matching active subscriptions by enqueuing delivery jobs; `deliver()` (run
 * by the worker) signs the payload (HMAC-SHA256), POSTs it, and records the
 * attempt in `webhook_deliveries`. Failures throw so the queue retries with
 * backoff and finally dead-letters.
 *
 * @see docs/architecture/35-SYNC-ENGINE.md
 */
final class WebhookDispatcher
{
    /** Fan an event out to subscribers → one delivery job each. Returns count enqueued. */
    public function dispatch(string $event, array $payload, ?string $ownerType = null, ?int $ownerId = null): int
    {
        $b = Database::connect()->table('webhook_subscriptions')
            ->select('id')->where('event', $event)->where('status', 'active')->where('deleted_at', null);
        if ($ownerType !== null) {
            $b->where('owner_type', $ownerType)->where('owner_id', $ownerId);
        }
        $subs  = $b->get()->getResultArray();
        $queue = service('jobQueueRepository');
        $n     = 0;
        foreach ($subs as $s) {
            $queue->enqueue('webhook', 'webhook.deliver', ['subscription_id' => (int) $s['id'], 'event' => $event, 'payload' => $payload]);
            $n++;
        }

        return $n;
    }

    /** Deliver one webhook (called by the worker). Throws on non-2xx so it retries. */
    public function deliver(int $subscriptionId, string $event, array $payload, int $attemptNo = 1): bool
    {
        $db  = Database::connect();
        $sub = $db->table('webhook_subscriptions')->where('id', $subscriptionId)->get()->getRowArray();
        if ($sub === null) {
            return false;
        }

        $body      = (string) json_encode(['event' => $event, 'data' => $payload]);
        $secret    = (string) ($sub['secret_ref'] ?? 'dev-webhook-secret');
        $signature = hash_hmac('sha256', $body, $secret);

        $code   = 0;
        $status = 'failed';
        try {
            $res = service('curlrequest')->post((string) $sub['target_url'], [
                'headers'     => ['Content-Type' => 'application/json', 'X-Signature' => $signature, 'X-Event' => $event],
                'body'        => $body,
                'timeout'     => 8,
                'http_errors' => false,
            ]);
            $code   = $res->getStatusCode();
            $status = ($code >= 200 && $code < 300) ? 'delivered' : 'failed';
        } catch (Throwable) {
            $status = 'failed';
        }

        $db->table('webhook_deliveries')->insert([
            'webhook_subscription_id' => $subscriptionId, 'event' => $event, 'payload' => $body,
            'signature' => $signature, 'response_code' => $code ?: null, 'attempt_no' => $attemptNo,
            'delivered_at' => $status === 'delivered' ? date('Y-m-d H:i:s') : null, 'status' => $status,
        ]);

        if ($status !== 'delivered') {
            throw new \RuntimeException('Webhook delivery failed with HTTP ' . $code);
        }

        return true;
    }
}
