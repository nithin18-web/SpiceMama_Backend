<?php
declare(strict_types=1);

// Usage: php bin/migrate.php            show applied state and pending migrations (dry run)
//        php bin/migrate.php --apply    apply pending migrations
// Production execution requires owner approval and a verified backup first.
require __DIR__ . '/cli.php';

use SpiceMama\Support\Migrator;

$apply = in_array('--apply', array_slice($argv, 1), true);
if (array_diff(array_slice($argv, 1), ['--apply']) !== []) {
    cli_fail('Usage: php bin/migrate.php [--apply]');
}
$config = cli_config($app);
$db = cli_db($config);
$migrator = new Migrator($db, $app . '/database/migrations');
try {
    $pending = $migrator->pending();
    echo 'Database: ' . $db->query('SELECT DATABASE()')->fetchColumn() . "\n";
    if ($pending === []) {
        echo "Up to date.\n";
        exit(0);
    }
    echo 'Pending: ' . implode(', ', array_keys($pending)) . "\n";
    if (!$apply) {
        echo "Dry run only. Re-run with --apply to execute.\n";
        exit(0);
    }
    $migrator->apply(fn(string $version) => print("Applied $version\n"));
} catch (RuntimeException $error) {
    cli_fail('Migration stopped: ' . $error->getMessage());
}
