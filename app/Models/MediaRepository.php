<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * MediaRepository — reads/links for media_assets + product_media.
 */
final class MediaRepository
{
    /** @return array<string,mixed>|null Active asset by uuid (for serving). */
    public function findByUuid(string $uuid): ?array
    {
        $row = Database::connect()->table('media_assets')
            ->select('uuid, bucket, object_key, mime, visibility, status')
            ->where('uuid', $uuid)->where('deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    public function attachToProduct(int $productId, int $mediaId, bool $primary, ?int $actorId): bool
    {
        return (bool) Database::connect()->table('product_media')->insert([
            'product_id' => $productId, 'media_id' => $mediaId,
            'sort_order' => $primary ? 0 : 1, 'is_primary' => $primary ? 1 : 0, 'created_by' => $actorId,
        ]);
    }

    /** @return list<array<string,mixed>> Product images (uuid + primary), for display. */
    public function forProduct(int $productId): array
    {
        return Database::connect()->table('product_media pm')
            ->select('pm.media_id, ma.uuid, pm.is_primary, pm.sort_order')
            ->join('media_assets ma', 'ma.id = pm.media_id', 'left')
            ->where('pm.product_id', $productId)->where('pm.deleted_at', null)->where('ma.deleted_at', null)
            ->where('ma.status', 'active')   // only servable assets (MediaController::serve requires active)
            ->orderBy('pm.is_primary', 'DESC')->orderBy('pm.sort_order', 'ASC')
            ->get()->getResultArray();
    }

    /** Vendor's reusable image library (vendor- or shop-owned), newest first. @return list<array<string,mixed>> */
    public function vendorImages(int $vendorId, int $limit = 300): array
    {
        return Database::connect()->table('media_assets')
            ->select('id, uuid, mime')
            ->where('status', 'active')->where('deleted_at', null)->like('mime', 'image/', 'after')
            ->groupStart()
                ->groupStart()->where('owner_type', 'vendor')->where('owner_id', $vendorId)->groupEnd()
                ->orGroupStart()->where('owner_type', 'shop')->where('owner_id IN (SELECT id FROM shops WHERE vendor_id = ' . (int) $vendorId . ')', null, false)->groupEnd()
            ->groupEnd()
            ->orderBy('id', 'DESC')->limit($limit)
            ->get()->getResultArray();
    }

    /** Does this image belong to the vendor (directly or via one of its shops)? */
    public function vendorOwnsImage(int $vendorId, int $mediaId): bool
    {
        return (bool) Database::connect()->table('media_assets')
            ->where('id', $mediaId)->where('status', 'active')->where('deleted_at', null)
            ->groupStart()
                ->groupStart()->where('owner_type', 'vendor')->where('owner_id', $vendorId)->groupEnd()
                ->orGroupStart()->where('owner_type', 'shop')->where('owner_id IN (SELECT id FROM shops WHERE vendor_id = ' . (int) $vendorId . ')', null, false)->groupEnd()
            ->groupEnd()
            ->countAllResults();
    }

    public function isAttached(int $productId, int $mediaId): bool
    {
        return (bool) Database::connect()->table('product_media')
            ->where('product_id', $productId)->where('media_id', $mediaId)->where('deleted_at', null)->countAllResults();
    }

    public function hasPrimary(int $productId): bool
    {
        return (bool) Database::connect()->table('product_media')
            ->where('product_id', $productId)->where('is_primary', 1)->where('deleted_at', null)
            ->countAllResults();
    }

    /** Persist a new gallery order. @param list<int> $mediaIds in display order */
    public function reorder(int $productId, array $mediaIds, ?int $actorId = null): bool
    {
        $db = Database::connect();
        foreach (array_values($mediaIds) as $i => $mid) {
            $db->table('product_media')->where('product_id', $productId)->where('media_id', (int) $mid)->where('deleted_at', null)
                ->update(['sort_order' => $i, 'updated_by' => $actorId]);
        }

        return true;
    }

    public function setPrimaryImage(int $productId, int $mediaId, ?int $actorId = null): bool
    {
        $db = Database::connect();
        $db->table('product_media')->where('product_id', $productId)->where('deleted_at', null)->update(['is_primary' => 0]);

        return $db->table('product_media')->where('product_id', $productId)->where('media_id', $mediaId)->where('deleted_at', null)
            ->update(['is_primary' => 1, 'updated_by' => $actorId]);
    }

    public function detach(int $productId, int $mediaId, ?int $actorId = null): bool
    {
        return Database::connect()->table('product_media')->where('product_id', $productId)->where('media_id', $mediaId)
            ->update(['deleted_at' => date('Y-m-d H:i:s'), 'updated_by' => $actorId]);
    }

    /** @return list<array<string,mixed>> product documents (manuals/warranty/etc.) */
    public function documents(int $productId): array
    {
        return Database::connect()->table('product_documents pd')
            ->select('pd.id, pd.doc_type, pd.title, ma.uuid')
            ->join('media_assets ma', 'ma.id = pd.media_id', 'left')
            ->where('pd.product_id', $productId)->where('pd.deleted_at', null)
            ->orderBy('pd.sort_order')->get()->getResultArray();
    }

    public function addDocument(int $productId, int $mediaId, string $docType, string $title, ?int $actorId = null): bool
    {
        $types = ['manual', 'brochure', 'catalog', 'certificate', 'warranty', 'spec_sheet'];

        return (bool) Database::connect()->table('product_documents')->insert([
            'uuid' => $this->uuid(), 'product_id' => $productId, 'media_id' => $mediaId,
            'doc_type' => in_array($docType, $types, true) ? $docType : 'manual',
            'title' => mb_substr($title, 0, 191) ?: null, 'created_by' => $actorId,
        ]);
    }

    public function deleteDocument(int $productId, int $docId, ?int $actorId = null): bool
    {
        return Database::connect()->table('product_documents')->where('id', $docId)->where('product_id', $productId)
            ->update(['deleted_at' => date('Y-m-d H:i:s'), 'updated_by' => $actorId]);
    }

    private function uuid(): string
    {
        $d    = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
