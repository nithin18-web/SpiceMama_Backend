<?php
declare(strict_types=1);

// Adds one published product from a JSON spec (see deploy/products/*.json).
// Usage: php deploy/new-product.php <spec.json> <existing-output-dir> [--apply-local]
//   Always writes <output-dir>/uploads/products/<photo> and <output-dir>/04-new-product-<slug>.sql
//   for phpMyAdmin on the live server. --apply-local also runs the same SQL on the LOCAL database.
// Input is validated like the admin API; the photo goes through ImageProcessor. The SQL looks up the
// category by slug, links the photo via LAST_INSERT_ID(), and fails (duplicate slug) if run twice.
require dirname(__DIR__) . '/backend/bin/cli.php';

use SpiceMama\Http\{ApiException, Kernel};
use SpiceMama\Services\{ImageProcessor, ImageStore};
use SpiceMama\Support\Clock;
use SpiceMama\Validation\AdminInput;

[$specPath, $outArg] = [$argv[1] ?? '', $argv[2] ?? ''];
$out = realpath($outArg) ?: cli_fail('Usage: php deploy/new-product.php <spec.json> <existing-output-dir> [--apply-local]');
$applyLocal = in_array('--apply-local', array_slice($argv, 3), true);
$spec = json_decode((string) @file_get_contents($specPath), true) ?? cli_fail("Cannot read JSON spec $specPath");
$repoRoot = dirname($app);

try {
    $input = AdminInput::productCreate(array_intersect_key($spec, array_flip(['name', 'slug', 'short_description', 'description', 'pack_size_label', 'ingredients', 'sort_order'])) + ['category_id' => 1]);
    AdminInput::altText(['alt_text' => (string) ($spec['alt_text'] ?? '')]);
} catch (ApiException $error) {
    cli_fail('Invalid spec: ' . $error->getMessage() . ' ' . json_encode($error->fields));
}
foreach (['short_description', 'description', 'alt_text'] as $field) {
    if (AdminInput::blank($spec[$field] ?? null)) {
        cli_fail("Spec needs a non-blank $field to be published.");
    }
}
$config = cli_config($app);
$db = cli_db($config);
$store = new ImageStore($out . '/uploads/products', Kernel::storage($config, 'upload-staging'));
$staged = $store->stagingPath();
try {
    $meta = (new ImageProcessor())->process($repoRoot . '/' . $spec['image'], $staged);
    $photo = $store->publish($staged, $meta['ext']);
} catch (ApiException $error) {
    cli_fail('Image rejected: ' . $error->getMessage());
} finally {
    if (is_file($staged)) {
        unlink($staged);
    }
}

$now = (new Clock())->db();
$q = fn(mixed $v): string => $v === null ? 'NULL' : (is_int($v) ? (string) $v : $db->quote((string) $v));
$statements = [
    sprintf("INSERT INTO products(category_id,name,slug,short_description,description,pack_size_label,ingredients,status,sort_order,published_at,created_at,updated_at) SELECT id,%s,%s,%s,%s,%s,%s,'published',%d,%s,%s,%s FROM categories WHERE slug=%s AND is_active=1",
        $q($input['name']), $q($input['slug']), $q($input['short_description']), $q($input['description']), $q($input['pack_size_label']), $q($input['ingredients']), $input['sort_order'], $q($now), $q($now), $q($now), $q($spec['category'])),
    'SET @new_product = LAST_INSERT_ID()',
    sprintf('INSERT INTO product_images(product_id,file_path,alt_text,sort_order,is_primary,width,height,mime_type,file_size_bytes,created_at) VALUES(@new_product,%s,%s,0,1,%d,%d,%s,%d,%s)',
        $q($photo), $q($spec['alt_text']), $meta['width'], $meta['height'], $q($meta['mime']), $meta['bytes'], $q($now)),
    sprintf("INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,details_json,created_at) VALUES(NULL,'product.created','product',@new_product,%s,%s)", $q(json_encode(['slug' => $input['slug'], 'via' => 'new-product.php'])), $q($now)),
];
$sqlFile = "$out/04-new-product-{$input['slug']}.sql";
file_put_contents($sqlFile, "-- SpiceMama: add product {$input['slug']} ($now UTC).\n-- Upload uploads/products/$photo to public_html/uploads/products/ FIRST, then run this in phpMyAdmin (spicemam_site).\n-- Expect: 1 row inserted (product), 1 row inserted (photo), 1 row inserted (audit). Running it twice fails on the duplicate slug.\nSTART TRANSACTION;\n" . implode(";\n", $statements) . ";\nCOMMIT;\n");
echo "Wrote $sqlFile and uploads/products/$photo ({$meta['width']}x{$meta['height']})\n";

if ($applyLocal) {
    $db->beginTransaction();
    try {
        foreach ($statements as $sql) {
            $db->exec($sql);
        }
        if ((int) $db->query('SELECT @new_product')->fetchColumn() < 1 || $db->query('SELECT ROW_COUNT()')->fetchColumn() === false) {
            throw new RuntimeException('Category not found or inactive.');
        }
        if (!copy("$out/uploads/products/$photo", $config['upload_path'] . "/$photo")) {
            throw new RuntimeException('Could not copy the photo into the local upload folder.');
        }
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        cli_fail('Not applied locally: ' . ($error instanceof PDOException ? 'database error ' . $error->getCode() : $error->getMessage()));
    }
    echo "Applied locally: product {$input['slug']} added and published.\n";
}
