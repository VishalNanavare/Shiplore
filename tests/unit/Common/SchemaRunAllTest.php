<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Audit B, P2b — run_all.sql claims to load "the entire schema" and did not.
 *
 * 28 of the 76 schema files were never SOURCEd. Measured by building both
 * versions against a real MySQL: the old file produced 231 tables, the corrected
 * one produces 251. Everything the manufacturer/monline feature set needs
 * (mshops, product_mshops, mfg_*), rider documents and finance, API idempotency,
 * claim logs and customer payment instruments was simply absent — as was
 * idx_ps_product_status, the covering index the storefront depends on.
 *
 * This test is the standing guard: adding a numbered schema file without wiring
 * it in fails here rather than silently producing an incomplete database.
 */
final class SchemaRunAllTest extends CIUnitTestCase
{
    private const DIR = ROOTPATH . 'database/sql/';

    /**
     * Files that legitimately are not part of a schema build.
     *
     * Kept as an explicit list rather than a pattern so adding one is a decision
     * somebody makes on purpose.
     */
    private const NOT_SCHEMA = [
        // One-off backfill over EXISTING vendor rows; a fresh database has nothing
        // to backfill, and running it is a no-op at best.
        '14_vendor_business_type_assign.sql',
        // Demo/sample data, loaded by hand when someone wants a populated panel.
        '99_demo_seed.sql',
        '99b_demo_staff_logins.sql',
        'manufacturer_products_demo.sql',
    ];

    /** @return list<string> */
    private function sourced(): array
    {
        $sql = (string) file_get_contents(self::DIR . 'run_all.sql');
        preg_match_all('/^SOURCE\s+([^\s;]+\.sql);/m', $sql, $m);

        return $m[1];
    }

    /** @return list<string> */
    private function onDisk(): array
    {
        $files = array_map('basename', (array) glob(self::DIR . '*.sql'));

        return array_values(array_diff($files, ['run_all.sql'], self::NOT_SCHEMA));
    }

    /** Every schema file on disk must be loaded by run_all.sql. */
    public function testEverySchemaFileIsSourced(): void
    {
        $missing = array_values(array_diff($this->onDisk(), $this->sourced()));
        sort($missing);

        $this->assertSame(
            [],
            $missing,
            "run_all.sql says it loads the entire schema but skips these — a database built "
            . "from it will be missing their tables and indexes:\n  " . implode("\n  ", $missing),
        );
    }

    /** And must not reference a file that no longer exists. */
    public function testEverySourcedFileExists(): void
    {
        $ghosts = [];

        foreach ($this->sourced() as $file) {
            if (! is_file(self::DIR . $file)) {
                $ghosts[] = $file;
            }
        }

        $this->assertSame([], $ghosts, 'run_all.sql SOURCEs files that are not on disk: ' . implode(', ', $ghosts));
    }

    /** Loading the same file twice would re-run its inserts and seeds. */
    public function testNoFileIsSourcedTwice(): void
    {
        $sourced = $this->sourced();
        $dupes   = array_values(array_unique(array_diff_assoc($sourced, array_unique($sourced))));

        $this->assertSame([], $dupes, 'sourced more than once: ' . implode(', ', $dupes));
    }

    /**
     * The storefront's covering indexes must be part of the schema build.
     *
     * These are the ones the C5 query-plan fix depends on. If the file defining
     * them ever drops out of run_all.sql again, a rebuilt database looks complete
     * and the storefront quietly returns to a full scan.
     */
    public function testStorefrontIndexesAreInTheBuild(): void
    {
        $sourced = $this->sourced();

        $this->assertContains('54_product_shops_index.sql', $sourced, 'idx_ps_product_status is defined here');
        $this->assertContains('75_catalog_indexes.sql', $sourced, 'idx_products_cat_status_del is defined here');
        $this->assertContains('43_product_shops.sql', $sourced, 'the product_shops table itself');

        // Dependency order: the table must be created before it is indexed.
        $this->assertLessThan(
            array_search('54_product_shops_index.sql', $sourced, true),
            array_search('43_product_shops.sql', $sourced, true),
            'product_shops must be created before the index is added to it',
        );
    }

    /**
     * The MariaDB-only syntax must stay documented.
     *
     * `ADD INDEX IF NOT EXISTS` is a syntax error on MySQL. Verified against MySQL
     * 9.6: the load halts, or with --force continues and leaves the database short
     * nine indexes while looking complete. Production is MariaDB, so this is a
     * portability trap for anyone building a local copy, not a production bug.
     */
    public function testMariaDbRequirementIsDocumented(): void
    {
        $run = (string) file_get_contents(self::DIR . 'run_all.sql');

        $this->assertMatchesRegularExpression(
            '/REQUIRES MariaDB/',
            $run,
            'run_all.sql must warn that MySQL cannot load it — a silently index-less database is worse than a failed load',
        );

        // And the claim must stay true: if someone converts those statements to
        // portable syntax, this test should be revisited rather than left lying.
        $usesMariaOnly = false;

        foreach ((array) glob(self::DIR . '*.sql') as $path) {
            if (preg_match('/ADD\s+(INDEX|KEY|UNIQUE)[^;]*IF\s+NOT\s+EXISTS/i', (string) file_get_contents($path)) === 1) {
                $usesMariaOnly = true;
                break;
            }
        }

        $this->assertTrue(
            $usesMariaOnly,
            'no file uses MariaDB-only ADD INDEX IF NOT EXISTS any more — the warning in run_all.sql is now stale, remove it',
        );
    }

    /**
     * ...and nothing may require MySQL, because the schema already requires MariaDB.
     *
     * A FUNCTIONAL INDEX — an expression used directly as a key part, written as a
     * doubled paren, `ADD UNIQUE KEY x ((CASE WHEN ...))` — is a MySQL 8.0.13+ feature.
     * MariaDB does not support functional indexes at ANY version; it indexes generated
     * columns instead, and answers with a 1064 syntax error at the opening double
     * paren.
     *
     * 50_rider_assign_unique.sql used to do exactly that, which made the schema
     * unbuildable end-to-end on EITHER engine: MySQL rejected the three
     * ADD INDEX IF NOT EXISTS files above, MariaDB rejected this one. An operator hit
     * it importing the full schema against MariaDB. It now uses a virtual generated
     * column with an ordinary UNIQUE index, which both engines accept and which
     * enforces the identical constraint.
     *
     * The two requirements are mutually exclusive, so this asserts the second half of
     * the pair the test above asserts the first half of.
     */
    public function testNoFileRequiresMySqlOnlySyntax(): void
    {
        $offenders = [];

        foreach ((array) glob(self::DIR . '*.sql') as $path) {
            // Strip -- comments: 50's docblock quotes the old form while explaining why
            // it no longer uses it, and a comment must not fail an assertion about code.
            $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($path)) ?? '';

            if (preg_match('/ADD\s+(?:UNIQUE\s+)?(?:INDEX|KEY)\s+`?\w+`?\s*\(\s*\(/i', $sql) === 1) {
                $offenders[] = basename($path);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'these use a MySQL-only functional index, which MariaDB cannot parse — index a '
            . 'generated column instead (see 50_rider_assign_unique.sql): ' . implode(', ', $offenders),
        );
    }
}
