<?php
declare(strict_types=1);

// DEVELOPMENT ONLY: loads database/seeds/dev-catalogue.json into the configured LOCAL database.
// Usage: php bin/seed-dev.php [--draft]
//   Products are published (visible in ?api=live) unless --draft is given.
// Goes through the same repositories and image pipeline as the admin API, so
// validation, re-encoding, gallery order and publishing rules all apply. Existing
// slugs are skipped, so re-running never duplicates or overwrites admin edits.
// Refuses to run unless the config is a loopback development config (secure_cookie false).
require __DIR__ . '/cli.php';

use SpiceMama\Http\{ApiException, Kernel};
use SpiceMama\Repositories\{CategoryRepository, ImageRepository, ProductRepository};
use SpiceMama\Services\{ImageProcessor, ImageStore};
use SpiceMama\Support\Clock;
use SpiceMama\Validation\AdminInput;

$args = array_slice($argv, 1);
if (array_diff($args, ['--draft']) !== []) {
    cli_fail('Usage: php bin/seed-dev.php [--draft]');
}
$config = cli_config($app);
if (($config['secure_cookie'] ?? true) !== false) {
    cli_fail('Refusing to seed: this is not a local development configuration.');
}
$db = cli_db($config);
$clock = new Clock();
$seed = json_decode((string) file_get_contents($app . '/database/seeds/dev-catalogue.json'), true, 32, JSON_THROW_ON_ERROR);
$repoRoot = dirname($app);
$categories = new CategoryRepository($db, $clock);
$products = new ProductRepository($db, $clock);
$images = new ImageRepository($db, $clock);
$store = new ImageStore((string) ($config['upload_path'] ?? ''), Kernel::storage($config, 'upload-staging'));
$processor = new ImageProcessor();

$slugIds = $db->query('SELECT slug,id FROM categories')->fetchAll(PDO::FETCH_KEY_PAIR);
$existing = array_flip($db->query('SELECT slug FROM products')->fetchAll(PDO::FETCH_COLUMN));
$added = ['categories' => 0, 'products' => 0, 'images' => 0];

try {
    foreach ($seed['categories'] as $category) {
        if (!isset($slugIds[$category['slug']])) {
            $slugIds[$category['slug']] = $categories->create(AdminInput::categoryCreate($category), null)['id'];
            ++$added['categories'];
        }
    }
    foreach ($seed['products'] as $item) {
        if (isset($existing[$item['slug']])) {
            echo "Skipped {$item['slug']} (already exists)\n";
            continue;
        }
        $input = $item;
        unset($input['images'], $input['category']);
        $input['category_id'] = $slugIds[$item['category']] ?? cli_fail("Unknown category {$item['category']}");
        $product = $products->create(AdminInput::productCreate($input), null);
        foreach ($item['images'] as $image) {
            $staged = $store->stagingPath();
            try {
                $meta = $processor->process($repoRoot . '/' . $image['file'], $staged);
                $name = $store->publish($staged, $meta['ext']);
            } finally {
                if (is_file($staged)) {
                    unlink($staged);
                }
            }
            try {
                $images->add($product['id'], $name, $meta, $image['alt_text'], null);
            } catch (Throwable $error) {
                $store->delete($name);
                throw $error;
            }
            ++$added['images'];
        }
        if (!in_array('--draft', $args, true)) {
            $products->setStatus($product['id'], 'published', null);
        }
        ++$added['products'];
        echo "Added {$item['slug']}\n";
    }
} catch (ApiException $error) {
    cli_fail('Seed stopped: ' . $error->getMessage() . ($error->fields !== [] ? ' ' . json_encode($error->fields) : ''));
}
echo "Done: {$added['categories']} categories, {$added['products']} products, {$added['images']} images added.\n";
