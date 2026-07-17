<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * BannerRepository — home-placement banner CRUD.
 *
 * Banners are displayed in the customer app carousel. At most 6 may be
 * active at once for the 'home' placement. Admins manage image_url via the
 * admin panel upload endpoint; media_id is reserved for future S3 use.
 */
class BannerRepository
{
    private BaseConnection $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    /**
     * Return up to $limit active home banners ordered by sort_order.
     * A banner is "active" when status='active' and now is within starts_at…ends_at
     * (NULL dates are treated as unbounded).
     *
     * @return array<int,array<string,mixed>>
     */
    public function activeForHome(int $limit = 6): array
    {
        $now = date('Y-m-d H:i:s');

        return $this->db->table('banners')
            ->select('id, title, subtitle, image_url, target_url, sort_order')
            ->where('placement', 'home')
            ->where('status', 'active')
            ->where('deleted_at IS NULL')
            ->groupStart()
                ->where('starts_at IS NULL')
                ->orWhere('starts_at <=', $now)
            ->groupEnd()
            ->groupStart()
                ->where('ends_at IS NULL')
                ->orWhere('ends_at >=', $now)
            ->groupEnd()
            ->orderBy('sort_order', 'ASC')
            ->limit($limit)
            ->get()
            ->getResultArray();
    }

    /**
     * All banners (all placements, all statuses, not deleted) for the admin list.
     *
     * @return array<int,array<string,mixed>>
     */
    public function list(): array
    {
        return $this->db->table('banners')
            ->select('id, title, subtitle, placement, image_url, target_url, sort_order, status, starts_at, ends_at, created_at')
            ->where('deleted_at IS NULL')
            ->orderBy('sort_order', 'ASC')
            ->orderBy('created_at', 'DESC')
            ->get()
            ->getResultArray();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->table('banners')
            ->where('id', $id)
            ->where('deleted_at IS NULL')
            ->get()
            ->getRowArray();

        return $row ?: null;
    }

    /**
     * Create a banner. Returns the new id, or null on failure.
     * Enforces max-6 active home banners before insert.
     *
     * @param  array<string,mixed> $data
     */
    public function create(array $data, int $actorId): ?int
    {
        if ($this->_wouldExceedLimit($data)) {
            return null;
        }

        $now = date('Y-m-d H:i:s');
        $this->db->table('banners')->insert(array_merge($data, [
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'created_at' => $now,
            'updated_at' => $now,
        ]));

        $id = (int) $this->db->insertID();
        return $id > 0 ? $id : null;
    }

    /**
     * Update an existing banner. Returns true on success.
     *
     * @param  array<string,mixed> $data
     */
    public function update(int $id, array $data, int $actorId): bool
    {
        if ($this->_wouldExceedLimit($data, $id)) {
            return false;
        }

        $this->db->table('banners')->where('id', $id)->where('deleted_at IS NULL')->update(
            array_merge($data, ['updated_by' => $actorId, 'updated_at' => date('Y-m-d H:i:s')])
        );

        return $this->db->affectedRows() > 0;
    }

    /** Soft-delete a banner. */
    public function delete(int $id, int $actorId): bool
    {
        $this->db->table('banners')->where('id', $id)->where('deleted_at IS NULL')->update([
            'deleted_at' => date('Y-m-d H:i:s'),
            'updated_by' => $actorId,
        ]);

        return $this->db->affectedRows() > 0;
    }

    /**
     * Returns true if creating/updating $data would push active home banners over 6.
     * When $excludeId is given (update case) that row is not counted.
     */
    private function _wouldExceedLimit(array $data, int $excludeId = 0): bool
    {
        if (($data['placement'] ?? '') !== 'home' || ($data['status'] ?? '') !== 'active') {
            return false;
        }

        $q = $this->db->table('banners')
            ->where('placement', 'home')
            ->where('status', 'active')
            ->where('deleted_at IS NULL');

        if ($excludeId > 0) {
            $q->where('id !=', $excludeId);
        }

        return (int) $q->countAllResults() >= 6;
    }
}
