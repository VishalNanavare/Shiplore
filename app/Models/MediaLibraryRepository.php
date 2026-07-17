<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * MediaLibraryRepository — owner-scoped access to media_assets for the general
 * media library (vendor- and shop-owned files). Folders are owners:
 * ('vendor', vendorId) and ('shop', shopId).
 */
final class MediaLibraryRepository
{
    /** Active files for one owner folder. @return list<array<string,mixed>> */
    public function listForOwner(string $ownerType, int $ownerId, int $limit = 500): array
    {
        return $this->base()->where('owner_type', $ownerType)->where('owner_id', $ownerId)
            ->orderBy('id', 'DESC')->limit($limit)->get()->getResultArray();
    }

    /** Active files across many owners of one type. @return list<array<string,mixed>> */
    public function listForOwners(string $ownerType, array $ownerIds, int $limit = 1000): array
    {
        if ($ownerIds === []) {
            return [];
        }

        return $this->base()->where('owner_type', $ownerType)->whereIn('owner_id', $ownerIds)
            ->orderBy('id', 'DESC')->limit($limit)->get()->getResultArray();
    }

    /** File counts grouped by owner_id for one type. @return array<int,int> */
    public function countByOwner(string $ownerType, array $ownerIds): array
    {
        if ($ownerIds === []) {
            return [];
        }
        $rows = Database::connect()->table('media_assets')
            ->select('owner_id, COUNT(*) AS c')
            ->where('owner_type', $ownerType)->whereIn('owner_id', $ownerIds)
            ->where('status', 'active')->where('deleted_at', null)
            ->groupBy('owner_id')->get()->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['owner_id']] = (int) $r['c'];
        }

        return $out;
    }

    public function countForOwner(string $ownerType, int $ownerId): int
    {
        return Database::connect()->table('media_assets')
            ->where('owner_type', $ownerType)->where('owner_id', $ownerId)
            ->where('status', 'active')->where('deleted_at', null)->countAllResults();
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $row = $this->base()->where('id', $id)->get()->getRowArray();

        return $row ?: null;
    }

    /** @param array<string,mixed> $data */
    public function insertAsset(array $data): int
    {
        $db = Database::connect();
        $db->table('media_assets')->insert($data);

        return (int) $db->insertID();
    }

    public function markDeleted(int $id, ?int $actorId = null): void
    {
        Database::connect()->table('media_assets')->where('id', $id)
            ->update(['status' => 'deleted', 'deleted_at' => date('Y-m-d H:i:s'), 'updated_by' => $actorId]);
    }

    private function base(): \CodeIgniter\Database\BaseBuilder
    {
        return Database::connect()->table('media_assets')
            ->select('id, uuid, object_key, mime, original_name, size_bytes, owner_type, owner_id, created_at')
            ->where('status', 'active')->where('deleted_at', null);
    }
}
