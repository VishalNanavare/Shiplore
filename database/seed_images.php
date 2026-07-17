<?php
/**
 * database/seed_images.php — Unique demo image per product
 * --------------------------------------------------------------------------
 * Gives every demo product (IDs 101-2250) its own unique image so the
 * storefront, product pages AND the vendor media library all show pictures.
 *
 *  - Images are owned by the product's VENDOR (owner_type='vendor') so they
 *    appear in that vendor's media panel.
 *  - bucket='local' => MediaController::serve reads from writable/uploads/<object_key>
 *    and the public URL is  /media/{uuid}.
 *  - Source: picsum.photos (deterministic ?seed=). Falls back to a locally
 *    generated GD placeholder if a download fails, so it works offline too.
 *  - Products where (id % 3 == 0) get a 2nd gallery image.
 *
 * Idempotent: existing files on disk are reused; demo media_assets rows are
 * rebuilt each run.
 *
 * Run:  php database/seed_images.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$env  = [];
foreach (file($root . '/.env') as $line) {
    if (preg_match('/^\s*(database\.default\.([a-zA-Z]+))\s*=\s*(.*)$/', $line, $m)) {
        $env[$m[2]] = trim($m[3]);
    }
}
$db = mysqli_connect(
    $env['hostname'] ?? 'localhost',
    $env['username'] ?? 'root',
    $env['password'] ?? '',
    $env['database'] ?? 'test',
    (int) ($env['port'] ?? 3306),
);
if (!$db) { fwrite(STDERR, "DB connect failed\n"); exit(1); }
mysqli_set_charset($db, 'utf8mb4');

function q(string $sql) {
    global $db;
    $r = mysqli_query($db, $sql);
    if ($r === false) { fwrite(STDERR, "SQL ERROR: " . mysqli_error($db) . "\n  >> " . substr($sql, 0, 300) . "\n"); exit(1); }
    return $r;
}
function esc($v): string {
    global $db;
    if ($v === null) return 'NULL';
    if (is_int($v)) return (string) $v;
    return "'" . mysqli_real_escape_string($db, (string) $v) . "'";
}

$UP_DIR = $root . '/writable/uploads/demo-media';
if (!is_dir($UP_DIR) && !@mkdir($UP_DIR, 0775, true) && !is_dir($UP_DIR)) {
    fwrite(STDERR, "Cannot create $UP_DIR\n"); exit(1);
}

echo "==> Image seed starting\n";

/* Load demo products with their vendor + a label for the placeholder */
$products = [];
$r = q("SELECT p.id, p.vendor_id, p.title, c.name AS cat
        FROM products p JOIN categories c ON p.category_id=c.id
        WHERE p.id BETWEEN 101 AND 2250 ORDER BY p.id");
while ($row = mysqli_fetch_assoc($r)) {
    $products[(int) $row['id']] = ['vendor' => (int) $row['vendor_id'], 'title' => $row['title'], 'cat' => $row['cat']];
}
$total = count($products);
echo "    products to illustrate: $total\n";

/* Build the download job list: [objectKeyBase, url, destPath, productId, isPrimary, sortOrder] */
$jobs = [];
foreach ($products as $pid => $info) {
    $jobs[] = ['key' => "demo-media/prod-$pid.jpg", 'url' => "https://picsum.photos/seed/p$pid/800/600",
        'dest' => "$UP_DIR/prod-$pid.jpg", 'pid' => $pid, 'primary' => 1, 'sort' => 1, 'cat' => $info['cat']];
    if ($pid % 3 === 0) {
        $jobs[] = ['key' => "demo-media/prod-$pid-2.jpg", 'url' => "https://picsum.photos/seed/p{$pid}b/800/600",
            'dest' => "$UP_DIR/prod-$pid-2.jpg", 'pid' => $pid, 'primary' => 0, 'sort' => 2, 'cat' => $info['cat']];
    }
}
echo "    image files needed: " . count($jobs) . "\n";

/* ---- GD placeholder fallback ---- */
function gdPlaceholder(string $dest, int $pid, string $label): bool {
    if (!function_exists('imagecreatetruecolor')) return false;
    $w = 800; $h = 600;
    $img = imagecreatetruecolor($w, $h);
    // deterministic pleasant colour from pid
    $hue = ($pid * 47) % 360;
    [$r, $g, $b] = hsvToRgb($hue, 0.45, 0.75);
    $bg = imagecolorallocate($img, $r, $g, $b);
    imagefilledrectangle($img, 0, 0, $w, $h, $bg);
    [$r2, $g2, $b2] = hsvToRgb($hue, 0.55, 0.55);
    $accent = imagecolorallocate($img, $r2, $g2, $b2);
    imagefilledrectangle($img, 0, $h - 90, $w, $h, $accent);
    $white = imagecolorallocate($img, 255, 255, 255);
    $txt = substr($label, 0, 28);
    imagestring($img, 5, 30, $h - 60, $txt, $white);
    imagestring($img, 5, 30, 30, "#$pid", $white);
    $ok = imagejpeg($img, $dest, 82);
    imagedestroy($img);
    return $ok;
}
function hsvToRgb(float $h, float $s, float $v): array {
    $c = $v * $s; $x = $c * (1 - abs(fmod($h / 60, 2) - 1)); $m = $v - $c;
    [$r, $g, $b] = match (true) {
        $h < 60  => [$c, $x, 0], $h < 120 => [$x, $c, 0], $h < 180 => [0, $c, $x],
        $h < 240 => [0, $x, $c], $h < 300 => [$x, 0, $c], default => [$c, 0, $x],
    };
    return [(int) round(($r + $m) * 255), (int) round(($g + $m) * 255), (int) round(($b + $m) * 255)];
}

/* ---- parallel downloader (curl_multi) ---- */
function downloadBatch(array $batch): array { // returns [key => bool ok]
    $mh = curl_multi_init();
    $handles = [];
    $fps = [];
    $jobByKey = [];
    foreach ($batch as $j) {
        if (is_file($j['dest']) && filesize($j['dest']) > 1000) { continue; } // already have it
        $fp = fopen($j['dest'], 'w');
        if ($fp === false) continue;
        $ch = curl_init($j['url']);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_FAILONERROR => true,
            CURLOPT_USERAGENT => 'demo-seeder/1.0',
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$j['key']] = $ch; $fps[$j['key']] = $fp; $jobByKey[$j['key']] = $j;
    }
    do { $status = curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 1.0); }
    while ($running && $status === CURLM_OK);

    $result = [];
    foreach ($handles as $key => $ch) {
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
        fclose($fps[$key]);
        $dest = $jobByKey[$key]['dest'];
        $ok = ($code === 200 && is_file($dest) && filesize($dest) > 1000);
        if (!$ok) { // fallback to GD placeholder
            $ok = gdPlaceholder($dest, $jobByKey[$key]['pid'], $jobByKey[$key]['cat']);
        }
        $result[$key] = $ok;
    }
    curl_multi_close($mh);
    return $result;
}

/* run downloads in chunks */
echo "--> downloading / generating images (parallel)\n";
$chunks = array_chunk($jobs, 24);
$done = 0; $failed = 0; $reused = 0;
foreach ($chunks as $i => $chunk) {
    // pre-count reused
    $toFetch = [];
    foreach ($chunk as $j) {
        if (is_file($j['dest']) && filesize($j['dest']) > 1000) { $reused++; }
        else { $toFetch[] = $j; }
    }
    if ($toFetch) {
        $res = downloadBatch($toFetch);
        foreach ($res as $ok) { if (!$ok) $failed++; }
    }
    $done += count($chunk);
    if ($i % 10 === 0 || $done >= count($jobs)) {
        echo "    " . min($done, count($jobs)) . "/" . count($jobs) . " processed (reused=$reused, failed-dl=$failed)\n";
    }
}

/* ---- rebuild media_assets + product_media rows for the demo range ---- */
echo "--> wiring media_assets + product_media\n";
q("SET FOREIGN_KEY_CHECKS = 0");
q("DELETE FROM product_media WHERE product_id BETWEEN 101 AND 2250");
q("DELETE FROM media_assets WHERE object_key LIKE 'demo-media/%'");

// bulk insert media_assets
$rows = [];
foreach ($jobs as $j) {
    if (!is_file($j['dest'])) continue;
    $size = (int) filesize($j['dest']);
    $vendor = $products[$j['pid']]['vendor'];
    $name = basename($j['dest']);
    $rows[] = "(UUID()," . esc('local') . "," . esc($j['key']) . "," . esc('vendor') . "," . $vendor . "," .
              esc('public') . "," . esc('image/jpeg') . "," . esc($name) . "," . $size . "," . esc('active') . ")";
}
foreach (array_chunk($rows, 200) as $chunk) {
    q("INSERT INTO media_assets (uuid,bucket,object_key,owner_type,owner_id,visibility,mime,original_name,size_bytes,status) VALUES " . implode(',', $chunk));
}

// map object_key -> media id
$keyToId = [];
$r = q("SELECT id, object_key FROM media_assets WHERE object_key LIKE 'demo-media/%'");
while ($row = mysqli_fetch_assoc($r)) $keyToId[$row['object_key']] = (int) $row['id'];

// product_media
$pmRows = [];
foreach ($jobs as $j) {
    $mid = $keyToId[$j['key']] ?? null;
    if ($mid === null) continue;
    $pmRows[] = "(" . $j['pid'] . "," . $mid . "," . $j['sort'] . "," . $j['primary'] . ")";
}
foreach (array_chunk($pmRows, 300) as $chunk) {
    q("INSERT INTO product_media (product_id, media_id, sort_order, is_primary) VALUES " . implode(',', $chunk));
}
q("SET FOREIGN_KEY_CHECKS = 1");

/* ---- verification ---- */
echo "\n==> VERIFICATION\n";
foreach ([
    'media_assets (demo)'   => "SELECT COUNT(*) c FROM media_assets WHERE object_key LIKE 'demo-media/%'",
    'product_media rows'    => "SELECT COUNT(*) c FROM product_media WHERE product_id BETWEEN 101 AND 2250",
    'products w/ primary'   => "SELECT COUNT(*) c FROM product_media WHERE product_id BETWEEN 101 AND 2250 AND is_primary=1",
    'vendors w/ media'      => "SELECT COUNT(DISTINCT owner_id) c FROM media_assets WHERE object_key LIKE 'demo-media/%' AND owner_type='vendor'",
] as $label => $sql) {
    $row = mysqli_fetch_assoc(q($sql));
    printf("    %-22s %s\n", $label, $row['c']);
}
$row = mysqli_fetch_assoc(q("SELECT uuid FROM media_assets WHERE object_key='demo-media/prod-101.jpg' LIMIT 1"));
if ($row) echo "\n    Sample image URL:  /media/{$row['uuid']}\n";
echo "    Files on disk:     " . (iterator_count(new FilesystemIterator($UP_DIR))) . " in writable/uploads/demo-media/\n";

echo "\n==> DONE.\n";
mysqli_close($db);
