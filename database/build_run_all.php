<?php

declare(strict_types=1);

/**
 * database/build_run_all.php — flatten run_all.sql into ONE importable .sql file.
 *
 * WHY THIS EXISTS
 * run_all.sql contains no schema of its own: it is 78 lines of `SOURCE <file>.sql;`.
 * `SOURCE` is a command of the mysql/mariadb command-line CLIENT, not SQL, so nothing
 * that talks to the server directly can follow those includes — phpMyAdmin answers
 * "Unrecognized statement type ... near SOURCE", and database/apply_sql.php
 * (mysqli_multi_query) would do the same. The MySQL 9.6 client does not accept SOURCE
 * either, in batch or via -e, and this machine has no mariadb client. run_all.sql's own
 * header names the two ways out: use the MariaDB client, or expand the includes. This
 * is the second one.
 *
 * Usage:
 *   php database/build_run_all.php                     -> database/_full_schema.sql
 *   php database/build_run_all.php path/to/output.sql
 *
 * The output goes in database/, NOT database/sql/: that directory holds migrations,
 * every one of which SchemaRunAllTest requires run_all.sql to source. A generated
 * bundle there would fail that check, and rightly — it is an output, not a migration,
 * and sourcing it would make run_all.sql include itself.
 *
 * The output is byte-for-byte the same statements in the same order; only the SOURCE
 * lines are replaced by the files they name. Nothing is rewritten or reordered, because
 * the order is dependency-sensitive (FOREIGN_KEY_CHECKS stays 0 across 00-08 and is
 * re-enabled in 09).
 *
 * TWO THINGS TO KNOW BEFORE IMPORTING THE RESULT
 *
 * 1. It still REQUIRES MariaDB, for the same reason run_all.sql does: four files use
 *    `ALTER TABLE ... ADD INDEX IF NOT EXISTS`, which MySQL rejects. On MySQL the load
 *    either halts or — with --force — silently continues and leaves the database short
 *    of nine indexes, including the two the storefront's query plan depends on. A
 *    MySQL-built copy looks complete and performs nothing like production.
 * 2. Eight of the included files use DELIMITER (16, 17, 18, 22, 23, 25, 29, 70) to
 *    define stored procedures. phpMyAdmin parses DELIMITER itself, so importing there
 *    is fine. A tool that does NOT parse it (mysqli_multi_query, and therefore
 *    apply_sql.php) will fail on those blocks — for those, use the CLI or import this
 *    file through phpMyAdmin.
 */

if (PHP_SAPI !== 'cli') {
    // Same reasoning as apply_sql.php and seed_big.php: this reads the whole schema off
    // disk and must never be reachable over the web.
    http_response_code(404);
    exit;
}

$sqlDir = __DIR__ . '/sql';
$runAll = $sqlDir . '/run_all.sql';
$out    = $argv[1] ?? (__DIR__ . '/_full_schema.sql');

if (! is_file($runAll)) {
    fwrite(STDERR, "cannot find {$runAll}\n");
    exit(1);
}

$lines    = file($runAll, FILE_IGNORE_NEW_LINES);
$expanded = [];
$included = 0;
$missing  = [];

foreach ($lines as $line) {
    // `SOURCE x.sql;` / `source x.sql` — optionally indented, optional semicolon.
    if (! preg_match('/^\s*SOURCE\s+(\S+?)\s*;?\s*$/i', $line, $m)) {
        $expanded[] = $line;

        continue;
    }

    $name = $m[1];
    $path = $sqlDir . '/' . $name;

    if (! is_file($path)) {
        // Do NOT silently skip: a missing include is how a rebuilt database ends up
        // quietly short of tables. Record it and fail at the end.
        $missing[] = $name;
        $expanded[] = '-- !! MISSING INCLUDE: ' . $name;

        continue;
    }

    $body = (string) file_get_contents($path);

    $expanded[] = '';
    $expanded[] = '-- ' . str_repeat('=', 68);
    $expanded[] = '-- BEGIN ' . $name;
    $expanded[] = '-- ' . str_repeat('=', 68);
    $expanded[] = rtrim($body, "\r\n");
    // A file that does not end in a statement terminator would otherwise run straight
    // into the next one.
    if (! preg_match('/;\s*$/', rtrim($body))) {
        $expanded[] = ';';
    }
    $expanded[] = '-- END ' . $name;
    $included++;
}

if ($missing !== []) {
    fwrite(STDERR, "ABORT: " . count($missing) . " include(s) not found:\n");
    foreach ($missing as $name) {
        fwrite(STDERR, "  - {$name}\n");
    }
    fwrite(STDERR, "Nothing was written; a partial schema is worse than none.\n");
    exit(1);
}

$header = <<<TXT
-- =====================================================================
-- GENERATED FILE — do not edit. Rebuild with:
--     php database/build_run_all.php
--
-- run_all.sql with all {$included} SOURCE includes expanded inline, so this can be
-- imported by anything that talks to the server directly (phpMyAdmin, Adminer,
-- any client). SOURCE is a CLIENT command and cannot be used that way.
--
-- STILL REQUIRES MariaDB: four included files use MariaDB-only
-- `ADD INDEX IF NOT EXISTS`. On MySQL the import halts, or silently leaves the
-- database missing nine indexes and performing nothing like production.
-- =====================================================================

TXT;

file_put_contents($out, $header . implode("\n", $expanded) . "\n");

printf(
    "wrote %s\n  %d files expanded, %s\n",
    $out,
    $included,
    number_format(filesize($out) / 1048576, 1) . ' MB',
);
