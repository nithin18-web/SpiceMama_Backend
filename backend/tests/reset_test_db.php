<?php
declare(strict_types=1);
// Drops every table in the configured disposable *_test database and re-applies migrations.
// Usable standalone (php tests/reset_test_db.php) or included; refuses non-test databases.
require_once dirname(__DIR__) . '/bootstrap/autoload.php';

use SpiceMama\Support\{Config, Database, Migrator};

function reset_test_db(string $app): array
{
    $config = Config::load(Config::path($app));
    $database = (string) ($config['database']['database'] ?? '');
    if (!str_ends_with($database, '_test')) {
        fwrite(STDERR, "Refusing to run: set DB_DATABASE to a disposable *_test database.\n");
        exit(1);
    }
    $db = Database::connect($config['database']);
    $db->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($db->query('SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $db->exec('DROP TABLE `' . str_replace('`', '', $table) . '`');
    }
    $db->exec('SET FOREIGN_KEY_CHECKS=1');
    $applied = (new Migrator($db, $app . '/database/migrations'))->apply();
    return [$config, $db, $applied];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    reset_test_db(dirname(__DIR__));
    echo "Test database reset.\n";
}
