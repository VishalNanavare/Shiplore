<?php

declare(strict_types=1);

/**
 * build/apply_sql.php — applies a numbered database/sql file to the DB from
 * .env (database.default.*). The repo has no mysql CLI on dev machines, so
 * this drives mysqli::multi_query instead. New migration files are written
 * DELIMITER-free (PREPARE-guarded ALTERs) so multi_query can execute them.
 *
 * Usage: php database/apply_sql.php database/sql/30_governance.sql [more.sql ...]
 */

if ($argc < 2) {
    fwrite(STDERR, "usage: php database/apply_sql.php <file.sql> [...]\n");
    exit(1);
}

$root = dirname(__DIR__);
$env  = [];
foreach (file($root . '/.env') as $line) {
    if (preg_match('/^\s*(database\.default\.[a-zA-Z]+)\s*=\s*(.*)$/', $line, $m)) {
        $env[$m[1]] = trim($m[2]);
    }
}

$db = mysqli_connect(
    $env['database.default.hostname'] ?? 'localhost',
    $env['database.default.username'] ?? 'root',
    $env['database.default.password'] ?? '',
    $env['database.default.database'] ?? 'test',
    (int) ($env['database.default.port'] ?? 3306),
);
if ($db === false) {
    fwrite(STDERR, 'connect failed: ' . mysqli_connect_error() . "\n");
    exit(1);
}
// Force utf8mb4 BEFORE loading any file, otherwise UTF-8 bytes (e.g. ₹) in the
// seed are stored double-encoded and surface as mojibake ("Ôé¦").
mysqli_set_charset($db, 'utf8mb4');

$fail = 0;
foreach (array_slice($argv, 1) as $file) {
    $path = str_starts_with($file, '/') || preg_match('/^[A-Za-z]:/', $file) ? $file : $root . '/' . $file;
    $sql  = file_get_contents($path);
    if ($sql === false) {
        fwrite(STDERR, "cannot read {$path}\n");
        $fail++;
        continue;
    }
    echo basename($path) . ' ... ';
    if (! mysqli_multi_query($db, $sql)) {
        echo "ERROR: " . mysqli_error($db) . "\n";
        $fail++;
        continue;
    }
    do {
        if ($r = mysqli_store_result($db)) {
            mysqli_free_result($r);
        }
    } while (mysqli_more_results($db) && mysqli_next_result($db));

    if (mysqli_errno($db) !== 0) {
        echo 'ERROR: ' . mysqli_error($db) . "\n";
        $fail++;
    } else {
        echo "ok\n";
    }
}

exit($fail === 0 ? 0 : 1);
