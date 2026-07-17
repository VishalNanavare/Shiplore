<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * VendorWarehouseRepository — a vendor's own warehouses (future expansion).
 * Scoped by vendor_id.
 */
final class VendorWarehouseRepository
{
    /** @return list<array<string,mixed>> */
    public function list(int $vendorId): array
    {
        return Database::connect()->table('warehouses')
            ->select('id, name, code, pincode, state_code, status')
            ->where('vendor_id', $vendorId)->where('deleted_at', null)
            ->orderBy('name', 'ASC')
            ->get()->getResultArray();
    }
}
