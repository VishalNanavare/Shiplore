<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * AttributeRepository — admin-governed product attributes (size, color, …) used
 * to define variants. Lists attributes and toggles active/inactive.
 *
 * @see docs/architecture/24-ADMIN-PANEL.md (Attribute Management)
 */
final class AttributeRepository
{
    /** @return list<array<string,mixed>> */
    public function list(?string $status = null): array
    {
        $builder = Database::connect()->table('attributes')
            ->select('id, code, name, type, is_variant_defining, status')
            ->where('deleted_at', null)
            ->orderBy('id', 'ASC');

        if ($status !== null && $status !== '') {
            $builder->where('status', $status);
        }

        return $builder->get()->getResultArray();
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $row = Database::connect()->table('attributes')
            ->where('id', $id)->where('deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    public function updateStatus(int $id, string $status, ?int $actorId = null): bool
    {
        return Database::connect()->table('attributes')
            ->where('id', $id)->where('deleted_at', null)
            ->update(['status' => $status, 'updated_by' => $actorId]);
    }

    /**
     * Products (by title, deduped) that currently reference this attribute on a
     * published listing — either as a non-variant spec value or via any of the
     * attribute's variant-defining values. Empty array = safe to deactivate/delete.
     *
     * @return list<string>
     */
    public function inUseBy(int $attributeId): array
    {
        $db = Database::connect();

        $viaSpec = $db->table('product_attribute_values pav')
            ->select('p.title')
            ->join('products p', 'p.id = pav.product_id')
            ->where('pav.attribute_id', $attributeId)
            ->where('p.status', 'published')
            ->get()->getResultArray();

        $viaVariant = $db->table('variant_attribute_values vav')
            ->select('p.title')
            ->join('product_variants pv', 'pv.id = vav.variant_id')
            ->join('products p', 'p.id = pv.product_id')
            ->where('vav.attribute_id', $attributeId)
            ->where('p.status', 'published')
            ->get()->getResultArray();

        $titles = array_merge(
            array_column($viaSpec, 'title'),
            array_column($viaVariant, 'title'),
        );

        return array_values(array_unique($titles));
    }

    public function delete(int $id, ?int $actorId = null): bool
    {
        return Database::connect()->table('attributes')
            ->where('id', $id)->where('deleted_at', null)
            ->update(['deleted_at' => date('Y-m-d H:i:s'), 'updated_by' => $actorId]);
    }
}
