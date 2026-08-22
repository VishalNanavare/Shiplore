<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * AttributeValueRepository — the controlled vocabulary for select/multiselect-type
 * attributes (e.g. Color -> Red/Blue/White). Before this class existed, no admin
 * screen could add, edit, or remove values at all; the only code touching
 * attribute_values was a read-only lookup.
 *
 * delete()/updateStatus() carry no built-in in-use guard — same separation
 * AttributeController/AttributeRepository already use: the repository can always act,
 * the controller decides whether to, by checking inUseBy() first.
 */
final class AttributeValueRepository
{
    /** @return list<array<string,mixed>> */
    public function listForAttribute(int $attributeId): array
    {
        return Database::connect()->table('attribute_values')
            ->where('attribute_id', $attributeId)->where('deleted_at', null)
            ->orderBy('sort_order', 'ASC')->orderBy('value', 'ASC')
            ->get()->getResultArray();
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $row = Database::connect()->table('attribute_values')
            ->where('id', $id)->where('deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    /** @param array<string,mixed> $data */
    public function create(array $data, ?int $actorId): int
    {
        $db = Database::connect();
        $db->table('attribute_values')->insert([
            'attribute_id' => (int) $data['attribute_id'],
            'value'        => mb_substr((string) $data['value'], 0, 191),
            'sort_order'   => (int) ($data['sort_order'] ?? 0),
            'created_by'   => $actorId,
        ]);

        return (int) $db->insertID();
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data, ?int $actorId): bool
    {
        $set = ['updated_by' => $actorId];
        if (array_key_exists('value', $data)) {
            $set['value'] = mb_substr((string) $data['value'], 0, 191);
        }
        if (array_key_exists('sort_order', $data)) {
            $set['sort_order'] = (int) $data['sort_order'];
        }

        return Database::connect()->table('attribute_values')
            ->where('id', $id)->where('deleted_at', null)
            ->update($set);
    }

    public function updateStatus(int $id, string $status, ?int $actorId = null): bool
    {
        return Database::connect()->table('attribute_values')
            ->where('id', $id)->where('deleted_at', null)
            ->update(['status' => $status, 'updated_by' => $actorId]);
    }

    public function delete(int $id, ?int $actorId = null): bool
    {
        return Database::connect()->table('attribute_values')
            ->where('id', $id)->where('deleted_at', null)
            ->update(['deleted_at' => date('Y-m-d H:i:s'), 'updated_by' => $actorId]);
    }

    /**
     * Products (by title, deduped) that currently reference this specific value on a
     * published listing — either as a non-variant spec value or via a variant. Empty
     * array = safe to deactivate/delete.
     *
     * @return list<string>
     */
    public function inUseBy(int $attributeValueId): array
    {
        $db = Database::connect();

        $viaSpec = $db->table('product_attribute_values pav')
            ->select('p.title')
            ->join('products p', 'p.id = pav.product_id')
            ->where('pav.attribute_value_id', $attributeValueId)
            ->where('p.status', 'published')
            ->get()->getResultArray();

        $viaVariant = $db->table('variant_attribute_values vav')
            ->select('p.title')
            ->join('product_variants pv', 'pv.id = vav.variant_id')
            ->join('products p', 'p.id = pv.product_id')
            ->where('vav.attribute_value_id', $attributeValueId)
            ->where('p.status', 'published')
            ->get()->getResultArray();

        $titles = array_merge(
            array_column($viaSpec, 'title'),
            array_column($viaVariant, 'title'),
        );

        return array_values(array_unique($titles));
    }
}
