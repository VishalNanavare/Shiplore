<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;
use Throwable;

/**
 * SupplierRepository — vendor-scoped supplier master (distinct from marketplace
 * vendors). Ports the WPF Shiplore supplier fields: name, gstin, aadhaar, phone,
 * email, address, city, state, pincode, salesman + salesman phone.
 */
final class SupplierRepository
{
    private const COLS = 'id, name, gstin, aadhaar_no, phone, email, address, city, state_code, pincode, salesman_name, salesman_phone, status';

    /** @return list<array<string,mixed>> */
    public function search(int $vendorId, string $q, int $limit = 20): array
    {
        $b = Database::connect()->table('suppliers')->select(self::COLS)
            ->where('vendor_id', $vendorId)->where('deleted_at', null);
        $q = trim($q);
        if ($q !== '') {
            $b->groupStart()->like('name', $q)->orLike('phone', $q)->orLike('gstin', $q)->groupEnd();
        }

        return $b->orderBy('name', 'ASC')->limit($limit)->get()->getResultArray();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id, int $vendorId): ?array
    {
        return Database::connect()->table('suppliers')->select(self::COLS)
            ->where('id', $id)->where('vendor_id', $vendorId)->where('deleted_at', null)
            ->get()->getRowArray() ?: null;
    }

    /** @param array<string,mixed> $d @return int|null new id */
    public function create(int $vendorId, array $d, ?int $actorId = null): ?int
    {
        $db = Database::connect();
        try {
            $db->table('suppliers')->insert($this->row($d) + [
                'uuid' => bin2hex(random_bytes(18)), 'vendor_id' => $vendorId, 'created_by' => $actorId,
            ]);

            return (int) $db->insertID();
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $d */
    public function update(int $id, int $vendorId, array $d, ?int $actorId = null): bool
    {
        $db = Database::connect();

        return (bool) $db->table('suppliers')
            ->where('id', $id)->where('vendor_id', $vendorId)->where('deleted_at', null)
            ->set('row_version', 'row_version + 1', false)
            ->update($this->row($d) + ['updated_by' => $actorId]);
    }

    /** Shared column map; trims + caps lengths. @param array<string,mixed> $d @return array<string,mixed> */
    private function row(array $d): array
    {
        $s = static fn ($k, $n) => ($v = trim((string) ($d[$k] ?? ''))) !== '' ? mb_substr($v, 0, $n) : null;

        return [
            'name'           => mb_substr(trim((string) ($d['name'] ?? '')), 0, 191),
            'gstin'          => $s('gstin', 20),
            'aadhaar_no'     => $s('aadhaar_no', 20),
            'phone'          => $s('phone', 25),
            'email'          => $s('email', 191),
            'address'        => $s('address', 500),
            'city'           => $s('city', 80),
            'state_code'     => $s('state_code', 2),
            'pincode'        => $s('pincode', 10),
            'salesman_name'  => $s('salesman_name', 120),
            'salesman_phone' => $s('salesman_phone', 20),
        ];
    }
}
