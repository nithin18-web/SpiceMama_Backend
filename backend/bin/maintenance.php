<?php
declare(strict_types=1);

// Usage: php bin/maintenance.php [--delete-orphans] [--purge-throttles]
// Default is a read-only report. Orphans are generated upload files with no
// database record (failed cleanup after removal or an interrupted upload).
// Orphans newer than one hour are skipped so in-flight uploads are untouched.
// Throttle purge removes rows untouched for 7 days (provisional retention).
require __DIR__ . '/cli.php';

use SpiceMama\Http\Kernel;
use SpiceMama\Services\ImageStore;

$args = array_slice($argv, 1);
if (array_diff($args, ['--delete-orphans', '--purge-throttles']) !== []) {
    cli_fail('Usage: php bin/maintenance.php [--delete-orphans] [--purge-throttles]');
}
$config = cli_config($app);
$db = cli_db($config);
$store = new ImageStore((string) ($config['upload_path'] ?? ''), Kernel::storage($config, 'upload-staging'));
$known = array_flip($db->query('SELECT file_path FROM product_images')->fetchAll(PDO::FETCH_COLUMN));
$orphans = array_filter($store->files(), fn(string $file): bool => !isset($known[$file]) && filemtime($config['upload_path'] . '/' . $file) < time() - 3600);
echo count($orphans) . " orphaned upload file(s).\n";
if (in_array('--delete-orphans', $args, true)) {
    foreach ($orphans as $file) {
        echo ($store->delete($file) ? 'Deleted ' : 'Could not delete ') . $file . "\n";
    }
}
$stale = $db->query("SELECT COUNT(*) FROM login_throttles WHERE updated_at < UTC_TIMESTAMP(6) - INTERVAL 7 DAY AND (blocked_until IS NULL OR blocked_until < UTC_TIMESTAMP(6))")->fetchColumn();
echo "$stale stale login throttle row(s).\n";
if (in_array('--purge-throttles', $args, true)) {
    $db->exec("DELETE FROM login_throttles WHERE updated_at < UTC_TIMESTAMP(6) - INTERVAL 7 DAY AND (blocked_until IS NULL OR blocked_until < UTC_TIMESTAMP(6))");
    echo "Purged.\n";
}
