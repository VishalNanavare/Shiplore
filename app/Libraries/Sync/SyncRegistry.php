<?php

declare(strict_types=1);

namespace App\Libraries\Sync;

/**
 * SyncRegistry — the catalogue of syncable entities. Each maps a logical sync
 * type to its table, optimistic-concurrency column and conflict strategy, so the
 * engine can pull deltas and reconcile pushes generically across every domain
 * (product, stock, price, GST, customer, order, payment, refund, delivery,
 * staff, promotion, notification, warehouse, transfer, branch config, POS).
 *
 * @see docs/architecture/35-SYNC-ENGINE.md
 */
final class SyncRegistry
{
    /** @var array<string,array{table:string,strategy:string}> */
    private const ENTITIES = [
        'product'       => ['table' => 'products',          'strategy' => 'server_wins'],
        'stock'         => ['table' => 'inventory',         'strategy' => 'last_write_wins'],
        'price'         => ['table' => 'product_variants',  'strategy' => 'server_wins'],
        'gst'           => ['table' => 'gst_verifications', 'strategy' => 'server_wins'],
        'customer'      => ['table' => 'customers',         'strategy' => 'server_wins'],
        'order'         => ['table' => 'orders',            'strategy' => 'server_wins'],
        'payment'       => ['table' => 'payments',          'strategy' => 'server_wins'],
        'refund'        => ['table' => 'refunds',           'strategy' => 'server_wins'],
        'delivery'      => ['table' => 'deliveries',        'strategy' => 'last_write_wins'],
        'staff'         => ['table' => 'vendor_staff',      'strategy' => 'server_wins'],
        'promotion'     => ['table' => 'promotions',        'strategy' => 'server_wins'],
        'notification'  => ['table' => 'notifications',     'strategy' => 'last_write_wins'],
        'warehouse'     => ['table' => 'warehouses',        'strategy' => 'server_wins'],
        'transfer'      => ['table' => 'stock_transfers',   'strategy' => 'server_wins'],
        'branch_config' => ['table' => 'shops',             'strategy' => 'server_wins'],
        'pos_sale'      => ['table' => 'pos_sales',         'strategy' => 'client_wins'],
    ];

    /**
     * Tenant column used to scope a generic pull to the caller's terminal. Entities
     * NOT listed here have no tenant column (global / cross-customer PII: customers,
     * orders, payments, refunds, gst, notifications, warehouses, transfers) and are
     * therefore NOT pullable through the generic, terminal-scoped sync engine.
     * @var array<string,string>
     */
    private const TENANT_COLUMN = [
        'product'       => 'vendor_id',
        'stock'         => 'shop_id',
        'price'         => 'vendor_id',
        'delivery'      => 'shop_id',
        'staff'         => 'vendor_id',
        'promotion'     => 'vendor_id',
        'branch_config' => 'vendor_id',
        'pos_sale'      => 'shop_id',
    ];

    public function isKnown(string $entity): bool
    {
        return isset(self::ENTITIES[$entity]);
    }

    /** The vendor_id/shop_id column that scopes this entity, or null if not tenant-pullable. */
    public function tenantColumn(string $entity): ?string
    {
        return self::TENANT_COLUMN[$entity] ?? null;
    }

    public function table(string $entity): ?string
    {
        return self::ENTITIES[$entity]['table'] ?? null;
    }

    public function strategy(string $entity): string
    {
        return self::ENTITIES[$entity]['strategy'] ?? 'server_wins';
    }

    /** @return list<string> */
    public function entities(): array
    {
        return array_keys(self::ENTITIES);
    }
}
