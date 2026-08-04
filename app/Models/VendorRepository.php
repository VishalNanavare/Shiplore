<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * VendorRepository — admin-side vendor listing + status transitions.
 * Platform-scoped reads (admin sees all vendors). Status changes feed the
 * vendor onboarding lifecycle (draft → submitted → approved → active …).
 *
 * @see docs/architecture/04-WORKFLOWS.md §4.1, 24-ADMIN-PANEL.md §2
 */
final class VendorRepository
{
    /**
     * Paginated vendor list. limit=0 means no limit.
     * @param array<string,mixed>|string|null $f keys: status, q, limit, offset
     * @return list<array<string,mixed>>
     */
    public function list(array|string|null $f = null, int $limit = 50, int $offset = 0): array
    {
        if (! is_array($f)) {
            $f = ['status' => $f];
        }
        $b = $this->baseVendorQuery();
        $this->applyVendorFilters($b, $f);
        $l = (int) ($f['limit'] ?? $limit);
        if ($l > 0) {
            $b->limit($l, (int) ($f['offset'] ?? $offset));
        }

        return $b->get()->getResultArray();
    }

    /** @param array<string,mixed> $f */
    public function countList(array $f = []): int
    {
        $b = $this->baseVendorQuery();
        $this->applyVendorFilters($b, $f);

        return $b->countAllResults();
    }

    private function baseVendorQuery(): object
    {
        return Database::connect()->table('vendors v')
            ->select('v.id, v.display_name, v.slug, v.gstin, v.gstin_status, v.status, v.created_at, v.party_type, v.business_type_id, bt.name AS business_type')
            ->join('business_types bt', 'bt.id = v.business_type_id', 'left')
            ->where('v.deleted_at', null)
            ->orderBy('v.created_at', 'DESC');
    }

    /** @param array<string,mixed> $f */
    private function applyVendorFilters(object $b, array $f): void
    {
        if (! empty($f['status'])) {
            $b->where('v.status', $f['status']);
        }
        // Manufacturers live in this same table under party_type='manufacturer'. Admin
        // screens must say which they want, or the two seller kinds mix silently.
        if (! empty($f['party_type'])) {
            $b->where('v.party_type', $f['party_type']);
        }
        if (isset($f['type']) && $f['type'] === 'unassigned') {
            $b->where('v.business_type_id', null);
        }
        if (isset($f['q']) && $f['q'] !== '') {
            $b->groupStart()->like('v.display_name', $f['q'])->orLike('v.slug', $f['q'])->orLike('v.gstin', $f['q'])->groupEnd();
        }
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $row = Database::connect()->table('vendors')
            ->where('id', $id)->where('deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    public function updateStatus(int $id, string $status, ?int $actorId = null): bool
    {
        return Database::connect()->table('vendors')
            ->where('id', $id)->where('deleted_at', null)
            ->update(['status' => $status, 'updated_by' => $actorId]);
    }

    /** @return array<string,mixed>|null Vendor + owner contact, for the edit form. */
    public function find(int $id): ?array
    {
        $row = Database::connect()->table('vendors v')
            ->select('v.id, v.legal_name, v.display_name, v.business_type_id, v.gstin, v.gstin_status, v.status, v.party_type, v.state_code, v.created_at, v.owner_user_id, u.email AS owner_email, u.phone AS owner_phone')
            ->join('users u', 'u.id = v.owner_user_id', 'left')
            ->where('v.id', $id)->where('v.deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    /** @param array<string,mixed> $d */
    public function update(int $id, array $d, ?int $actorId = null): bool
    {
        return Database::connect()->table('vendors')
            ->where('id', $id)->where('deleted_at', null)
            ->update([
                'legal_name'       => mb_substr((string) $d['legal_name'], 0, 191),
                'display_name'     => mb_substr((string) $d['display_name'], 0, 191),
                'business_type_id' => (int) $d['business_type_id'],
                'updated_by'       => $actorId,
            ]);
    }
}
