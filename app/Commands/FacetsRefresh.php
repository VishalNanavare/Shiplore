<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * FacetsRefresh — background recompute of browse-page COUNTS (pagination total +
 * category/brand/type/price facets) so the live request never aggregates. Two modes:
 *
 *   php spark facets:refresh                  drain the dirty queue (stale-while-
 *                                             revalidate markers + stock/zone/catalog
 *                                             changes) — run on a short cron (~60s).
 *   php spark facets:refresh --rebuild-global recompute the durable 'global' base —
 *                                             run on a slower cron (~10m) and once at deploy.
 *
 * All heavy aggregation lives here (via FacetCache → StoreCatalogRepository::compute*),
 * not in StoreController. See app/Libraries/Store/FacetCache.php.
 */
final class FacetsRefresh extends BaseCommand
{
    protected $group       = 'Ops';
    protected $name        = 'facets:refresh';
    protected $description = 'Recompute cached browse-page facet counts in the background.';
    protected $usage       = 'facets:refresh [--rebuild-global] [--limit N]';
    protected $options     = [
        '--rebuild-global' => 'Recompute the durable global base (tree + all categories).',
        '--limit'          => 'Max queue rows to drain in one run (default 200).',
    ];

    public function run(array $params): int
    {
        $facets = service('facetCache');

        if (array_key_exists('rebuild-global', $params)) {
            $res = $facets->rebuildGlobal();
            if (! empty($res['skipped'])) {
                CLI::write('facets:refresh — global rebuild already running, skipped.', 'yellow');

                return 0;
            }
            CLI::write('facets:refresh — global base rebuilt: tree + ' . CLI::color((string) ($res['categories'] ?? 0), 'green') . ' categories.');

            return 0;
        }

        $limit = (int) ($params['limit'] ?? CLI::getOption('limit') ?? 200);
        $res   = $facets->drainQueue($limit > 0 ? $limit : 200);
        if (! empty($res['skipped'])) {
            CLI::write('facets:refresh — drain already running, skipped.', 'yellow');

            return 0;
        }
        CLI::write('facets:refresh — refreshed ' . CLI::color((string) ($res['processed'] ?? 0), 'green') . ' queued key(s).');

        return 0;
    }
}
