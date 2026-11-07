<?php
declare(strict_types=1);

// Replaces each product's primary photo with its current pack image from frontend/assets/images/packs/<slug>.webp.
// Usage: php deploy/pack-photo-update.php <output-dir> [--apply-local]
//   Always writes <output-dir>/uploads/products/<new files> and <output-dir>/03-pack-photos.sql (phpMyAdmin)
//   for the live server. --apply-local also applies the same change to the configured LOCAL database.
// Images go through the same ImageProcessor as admin uploads. Every UPDATE is guarded by the old file
// name, so a row that no longer matches (edited since) is left untouched. Products without a pack file
// (photos uploaded by an admin) are skipped.
require dirname(__DIR__) . '/backend/bin/cli.php';

use SpiceMama\Http\Kernel;
use SpiceMama\Services\{ImageProcessor, ImageStore};
use SpiceMama\Support\Clock;

$out = realpath($argv[1] ?? '') ?: cli_fail('Usage: php deploy/pack-photo-update.php <existing-output-dir> [--apply-local]');
$applyLocal = in_array('--apply-local', array_slice($argv, 2), true);
$config = cli_config($app);
$db = cli_db($config);
$packs = dirname($app) . '/frontend/assets/images/packs';
$store = new ImageStore(rtrim($out, '/') . '/uploads/products', Kernel::storage($config, 'upload-staging'));
$processor = new ImageProcessor();
$now = (new Clock())->db();

$rows = $db->query('SELECT p.slug, i.id, i.product_id, i.file_path FROM products p JOIN product_images i ON i.product_id = p.id AND i.sort_order = 0 ORDER BY p.id')->fetchAll();
$updates = [];
foreach ($rows as $row) {
    $source = "$packs/{$row['slug']}.webp";
    if (!is_file($source)) {
        echo "skip {$row['slug']} (no pack image)\n";
        continue;
    }
    $staged = $store->stagingPath();
    try {
        $meta = $processor->process($source, $staged);
        $name = $store->publish($staged, $meta['ext']);
    } finally {
        if (is_file($staged)) {
            unlink($staged);
        }
    }
    $updates[] = $row + ['new' => $name, 'meta' => $meta];
    echo "{$row['slug']}: {$row['file_path']} -> $name ({$meta['width']}x{$meta['height']})\n";
}

$q = fn(mixed $v): string => is_int($v) ? (string) $v : $db->quote((string) $v);
$sql = "-- SpiceMama: replace product photos with the current pack images ($now UTC).\n-- Upload uploads/products/* to public_html/uploads/products/ FIRST, then run this in phpMyAdmin (spicemam_site).\nSTART TRANSACTION;\n";
foreach ($updates as $u) {
    $sql .= sprintf("UPDATE product_images SET file_path=%s, width=%d, height=%d, mime_type=%s, file_size_bytes=%d WHERE id=%d AND product_id=%d AND file_path=%s;\n",
        $q($u['new']), $u['meta']['width'], $u['meta']['height'], $q($u['meta']['mime']), $u['meta']['bytes'], $u['id'], $u['product_id'], $q($u['file_path']));
}
$sql .= "INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,details_json,created_at) VALUES(NULL,'catalogue.pack_photos_replaced','catalogue',NULL," . $q(json_encode(['count' => count($updates)])) . ",'$now');\nCOMMIT;\n";
$sql .= "-- Old photo files can then be deleted from public_html/uploads/products/:\n";
foreach ($updates as $u) {
    $sql .= "--   {$u['file_path']}\n";
}
file_put_contents(rtrim($out, '/') . '/03-pack-photos.sql', $sql);

if ($applyLocal) {
    $local = new ImageStore((string) ($config['upload_path'] ?? ''), Kernel::storage($config, 'upload-staging'));
    $db->beginTransaction();
    foreach (explode("\n", $sql) as $line) {
        if (str_starts_with($line, 'UPDATE ') || str_starts_with($line, 'INSERT ')) {
            $db->exec($line);
        }
    }
    foreach ($updates as $u) {
        if (!copy(rtrim($out, '/') . "/uploads/products/{$u['new']}", $config['upload_path'] . "/{$u['new']}")) {
            $db->rollBack();
            cli_fail('Could not copy photos into the local upload folder; nothing changed.');
        }
    }
    $db->commit();
    foreach ($updates as $u) {
        $local->delete($u['file_path']);
    }
    echo 'Applied locally: ' . count($updates) . " photos replaced.\n";
}
echo 'Wrote ' . count($updates) . " photo(s) and 03-pack-photos.sql to $out\n";
