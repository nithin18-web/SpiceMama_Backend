<?php
declare(strict_types=1);

// Writes phpMyAdmin-ready SQL for a server without SSH:
//   01-schema.sql     migration 001 plus its schema_migrations record (checksum matches the runner)
//   02-catalogue.sql  categories, products and photo records copied from the configured LOCAL database
// Usage: php deploy/export-catalogue.php <output-dir>   Prints the photo file names to copy.
// Admins, sessions, audit history and throttle data are never exported.
require dirname(__DIR__) . '/backend/bin/cli.php';

use SpiceMama\Support\Clock;

$out = $argv[1] ?? cli_fail('Usage: php deploy/export-catalogue.php <output-dir>');
$db = cli_db(cli_config($app));
$migration = $app . '/database/migrations/001_initial_schema.sql';
$now = (new Clock())->db();
$q = fn(mixed $value): string => $value === null ? 'NULL' : (is_int($value) ? (string) $value : $db->quote((string) $value));

file_put_contents("$out/01-schema.sql", file_get_contents($migration) . sprintf(
    "\nINSERT INTO schema_migrations(version,checksum,applied_at) VALUES('001_initial_schema','%s','%s');\n",
    hash_file('sha256', $migration), $now,
));

$sql = "-- SpiceMama launch catalogue exported from the local database at $now UTC.\n-- Import after 01-schema.sql into the empty production database.\nSET NAMES utf8mb4;\nSTART TRANSACTION;\n";
$tables = [
    'categories' => 'id,name,slug,description,sort_order,is_active,created_at,updated_at',
    'products' => 'id,category_id,name,slug,short_description,description,pack_size_label,ingredients,status,sort_order,published_at,created_at,updated_at',
    'product_images' => 'id,product_id,file_path,alt_text,sort_order,is_primary,width,height,mime_type,file_size_bytes,created_at',
];
$files = [];
foreach ($tables as $table => $columns) {
    foreach ($db->query("SELECT $columns FROM $table ORDER BY id")->fetchAll() as $row) {
        $sql .= "INSERT INTO $table($columns) VALUES(" . implode(',', array_map($q, $row)) . ");\n";
        if ($table === 'product_images') {
            $files[] = $row['file_path'];
        }
    }
}
$sql .= "INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,details_json,created_at) VALUES(NULL,'catalogue.imported','catalogue',NULL,NULL,'$now');\nCOMMIT;\n";
file_put_contents("$out/02-catalogue.sql", $sql);
echo implode("\n", $files), "\n";
