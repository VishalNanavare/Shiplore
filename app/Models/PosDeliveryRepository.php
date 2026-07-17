<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * PosDeliveryRepository — turns a POS sale into a delivery order. It writes a row
 * into the SAME `deliveries` table the web-order pipeline uses, so the existing
 * vendor delivery management (assign rider, track status, capture proof) handles
 * POS deliveries with no extra plumbing. The recipient is stored inline because a
 * POS delivery has no sub_order to read an address from.
 */
final class PosDeliveryRepository
{
    /** Create a pending delivery for a completed POS sale. @return int|null delivery id */
    public function createForSale(int $saleId, int $shopId, array $recipient, float $fee, string $etaDate = '', ?int $actorId = null): ?int
    {
        $name = trim((string) ($recipient['name'] ?? ''));
        $addr = trim((string) ($recipient['address'] ?? ''));
        if ($name === '' || $addr === '') {
            return null; // delivery needs at least a name + address
        }
        $db = Database::connect();
        $db->table('deliveries')->insert([
            'uuid' => bin2hex(random_bytes(18)), 'pos_sale_id' => $saleId, 'sub_order_id' => null, 'shop_id' => $shopId,
            'mode' => 'pos', 'delivery_fee' => number_format(max(0.0, $fee), 2, '.', ''),
            'eta_at' => $etaDate !== '' ? $etaDate . ' 18:00:00' : null,
            'recipient_name' => mb_substr($name, 0, 191), 'recipient_phone' => mb_substr((string) ($recipient['phone'] ?? ''), 0, 20),
            'recipient_address' => $addr, 'status' => 'pending', 'origin' => 'web_pos', 'created_by' => $actorId,
        ]);

        return (int) $db->insertID();
    }
}
