<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * RiderDocumentRepository — rider KYC documents (rider_documents) linked to the
 * stored file in media_assets (owner_type='rider'). Riders upload; admins verify
 * or reject. Mirrors the vendor_documents flow.
 */
final class RiderDocumentRepository
{
    public const TYPES = ['license', 'rc', 'insurance', 'pan', 'photo', 'other'];

    /** @return list<array<string,mixed>> the rider's documents + file uuid/mime */
    public function forRider(int $riderUserId): array
    {
        return Database::connect()->table('rider_documents rd')
            ->select('rd.id, rd.doc_type, rd.status, rd.expires_at, rd.note, rd.created_at, ma.uuid, ma.mime')
            ->join('media_assets ma', 'ma.id = rd.media_id', 'left')
            ->where('rd.rider_user_id', $riderUserId)->where('rd.deleted_at', null)
            ->orderBy('rd.id', 'DESC')->get()->getResultArray();
    }

    public function add(int $riderUserId, string $docType, int $mediaId, ?string $expiresAt, ?int $actorId = null): bool
    {
        return (bool) Database::connect()->table('rider_documents')->insert([
            'uuid'          => $this->uuid(),
            'rider_user_id' => $riderUserId,
            'doc_type'      => in_array($docType, self::TYPES, true) ? $docType : 'other',
            'media_id'      => $mediaId,
            'expires_at'    => $expiresAt ?: null,
            'status'        => 'uploaded',
            'created_by'    => $actorId,
        ]);
    }

    /** Admin decision: set status (verified|rejected) for a rider's document. */
    public function setStatus(int $docId, int $riderUserId, string $status, ?string $note, ?int $actorId = null): bool
    {
        if (! in_array($status, ['uploaded', 'verified', 'rejected'], true)) {
            return false;
        }
        $db = Database::connect();
        $db->table('rider_documents')->where('id', $docId)->where('rider_user_id', $riderUserId)->where('deleted_at', null)
            ->update(['status' => $status, 'note' => $note !== null ? mb_substr($note, 0, 191) : null, 'updated_by' => $actorId]);

        return $db->affectedRows() > 0;
    }

    public function delete(int $docId, int $riderUserId, ?int $actorId = null): bool
    {
        return (bool) Database::connect()->table('rider_documents')
            ->where('id', $docId)->where('rider_user_id', $riderUserId)
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
