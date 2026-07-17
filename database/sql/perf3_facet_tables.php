<?php

/**
 * perf3_facet_tables.php
 *
 * Tables backing the "cached exact counts + background recompute" architecture
 * (see app/Libraries/Store/FacetCache.php and app/Commands/FacetsRefresh.php):
 *
 *   - category_facet_summary  Precomputed exact facet payload per (scope_key,
 *                             category). scope_key is the GeoBucket cell key
 *                             ('global' for the un-scoped browse). Read as the
 *                             instant base + persistent backing for the cache.
 *
 *   - facet_refresh_queue     Dirty markers. The live request enqueues a row when
 *                             a cached payload is stale/missing (stale-while-
 *                             revalidate); stock/zone/catalog writes enqueue on
 *                             change. The facets:refresh worker drains + dedupes.
 *
 * Idempotent: guarded with CREATE TABLE IF NOT EXISTS. Run once:
 *   php database/sql/perf3_facet_tables.php
 */

$pdo = new PDO(
    'mysql:host=127.0.0.1;dbname=test;charset=utf8mb4',
    'root',
    'root@123',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$tables = [
    'category_facet_summary' => <<<'SQL'
        CREATE TABLE IF NOT EXISTS category_facet_summary (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            scope_key     VARCHAR(32)     NOT NULL,
            category_slug VARCHAR(190)    NOT NULL DEFAULT '',
            payload       JSON            NOT NULL,
            computed_at   INT UNSIGNED    NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_facet_scope_cat (scope_key, category_slug),
            KEY idx_facet_computed (computed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    'facet_refresh_queue' => <<<'SQL'
        CREATE TABLE IF NOT EXISTS facet_refresh_queue (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            scope_key     VARCHAR(32)     NOT NULL,
            category_slug VARCHAR(190)    NOT NULL DEFAULT '',
            reason        VARCHAR(24)     NOT NULL DEFAULT 'stale',
            enqueued_at   INT UNSIGNED    NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_queue_scope_cat (scope_key, category_slug),
            KEY idx_queue_enqueued (enqueued_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
];

foreach ($tables as $name => $ddl) {
    echo "Creating {$name} … ";
    $pdo->exec($ddl);
    echo "done\n";
}

echo "\nAll done.\n";
