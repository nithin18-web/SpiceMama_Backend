<?php
declare(strict_types=1);
// MariaDB integration suite. Rebuilds a disposable *_test database on every run.
// Usage: DB_DATABASE=spicemama_test php backend/tests/integration.php
// Uses config/local.php credentials; refuses any database not ending in _test.
require dirname(__DIR__) . '/bootstrap/autoload.php';
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

use SpiceMama\Http\{ApiException, Kernel, Request, Response};
use SpiceMama\Repositories\AdminRepository;
use SpiceMama\Services\SessionStore;
use SpiceMama\Support\{Clock, Database, Migrator};

final class ArraySession implements SessionStore
{
    public array $store = [];
    public int $regenerations = 0;

    public function start(): void {}

    public function &data(): array
    {
        return $this->store;
    }

    public function regenerate(): void
    {
        ++$this->regenerations;
    }

    public function destroy(): void
    {
        $this->store = [];
    }
}

$checks = 0;
function check(bool $condition, string $label): void
{
    global $checks;
    ++$checks;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $label);
    }
}

$app = dirname(__DIR__);
require __DIR__ . '/reset_test_db.php';
[$config, $db, $applied] = reset_test_db($app);
$database = $config['database']['database'];
check($applied === ['001_initial_schema'], 'migration applied');
check((new Migrator($db, $app . '/database/migrations'))->pending() === [], 'migration recorded');

// Migrator refuses edited history.
$copy = sys_get_temp_dir() . '/spicemama-mig-' . bin2hex(random_bytes(4));
mkdir($copy);
file_put_contents($copy . '/001_initial_schema.sql', file_get_contents($app . '/database/migrations/001_initial_schema.sql') . "\n-- edited\n");
try {
    (new Migrator($db, $copy))->pending();
    check(false, 'checksum drift rejected');
} catch (RuntimeException $error) {
    check(str_contains($error->getMessage(), 'modified'), 'checksum drift rejected');
}
unlink($copy . '/001_initial_schema.sql');
rmdir($copy);

$root = sys_get_temp_dir() . '/spicemama-it-' . bin2hex(random_bytes(4));
$uploads = $root . '/uploads';
$now = new DateTimeImmutable('2026-11-01 10:00:00', new DateTimeZone('UTC'));
$clock = new Clock(function () use (&$now): DateTimeImmutable { return $now; });
$session = new ArraySession();
$config['upload_path'] = $uploads;
$config['storage_path'] = $root . '/storage';
$config['throttle_key'] = str_repeat('k', 64);
$kernel = new Kernel(fn(): PDO => $db, $session, $clock, $config);
$password = 'correct horse battery staple';
$adminId = (new AdminRepository($db, $clock))->create('owner@example.test', 'MOCK Owner', $password);

/** Sends a request as the browser would; JSON bodies get the matching content type. */
function call(string $method, string $path, ?array $json = null, array $headers = [], array $query = [], array $form = [], array $files = [], string $ip = '127.0.0.1'): Response
{
    global $kernel;
    $body = '';
    if ($json !== null) {
        $body = json_encode((object) $json);
        $headers += ['content-type' => 'application/json'];
    }
    try {
        return $kernel->handle(new Request($method, '/api/v1' . $path, $query, $headers, $body, $form, $files, $ip));
    } catch (ApiException $error) {
        return Response::error($error);
    }
}
function token(): string
{
    return call('GET', '/admin/csrf')->body['data']['csrf_token'];
}
function write(string $method, string $path, ?array $json = null, array $extra = []): Response
{
    return call($method, $path, $json, ['x-csrf-token' => token()] + $extra);
}
function status(Response $response, int $status, string $label): array
{
    check($response->status === $status, $label . ' (got ' . $response->status . ' ' . json_encode($response->body) . ')');
    return $response->body ?? [];
}
function image(string $type, int $width = 40, int $height = 30, string $suffix = ''): array
{
    $file = tempnam(sys_get_temp_dir(), 'spicemama-img');
    $gd = imagecreatetruecolor($width, $height);
    imagefilledrectangle($gd, 0, 0, $width - 1, $height - 1, imagecolorallocate($gd, 200, 30, 20));
    match ($type) { 'jpeg' => imagejpeg($gd, $file), 'png' => imagepng($gd, $file), 'webp' => imagewebp($gd, $file) };
    file_put_contents($file, $suffix, FILE_APPEND);
    return upload($file);
}
function upload(string $file): array
{
    return ['file' => ['name' => 'x', 'type' => 'image/jpeg', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => filesize($file), 'uploaded' => true]];
}
function send(int $productId, array $files, string $alt = 'MOCK alt text'): Response
{
    return call('POST', '/admin/images', null, ['x-csrf-token' => token(), 'content-type' => 'multipart/form-data; boundary=x', 'content-length' => '1000'], [], ['product_id' => (string) $productId, 'alt_text' => $alt], $files);
}

// --- Authentication ---------------------------------------------------------
status(call('GET', '/admin/me'), 401, 'me requires session');
status(call('GET', '/admin/products'), 401, 'admin list requires session');
status(call('POST', '/admin/login', ['email' => 'owner@example.test', 'password' => $password]), 403, 'login requires pre-login CSRF');
$preLogin = token();
check(strlen($preLogin) === 64, 'pre-login token issued');
status(call('POST', '/admin/login', null, ['x-csrf-token' => $preLogin, 'content-type' => 'text/plain']), 415, 'login JSON media type');
status(call('POST', '/admin/login', ['email' => 'owner@example.test', 'password' => $password, 'remember' => true], ['x-csrf-token' => $preLogin]), 422, 'login unknown property');
$wrong = status(call('POST', '/admin/login', ['email' => 'owner@example.test', 'password' => 'nope'], ['x-csrf-token' => $preLogin]), 401, 'wrong password');
$unknown = status(call('POST', '/admin/login', ['email' => 'nobody@example.test', 'password' => 'nope'], ['x-csrf-token' => $preLogin]), 401, 'unknown email');
check($wrong === $unknown, 'generic login failure');
$login = status(call('POST', '/admin/login', ['email' => '  OWNER@example.test ', 'password' => $password], ['x-csrf-token' => $preLogin]), 200, 'login succeeds');
check($login['data'] === ['id' => $adminId, 'email' => 'owner@example.test', 'display_name' => 'MOCK Owner'], 'admin response shape');
check($session->regenerations === 1, 'session regenerated at login');
check(token() !== $preLogin, 'CSRF rotated after login');
status(call('GET', '/admin/me'), 200, 'me after login');
status(call('POST', '/admin/categories', ['name' => 'X', 'slug' => 'x']), 403, 'mutation without CSRF');
status(call('POST', '/admin/categories', ['name' => 'X', 'slug' => 'x'], ['x-csrf-token' => $preLogin]), 403, 'stale CSRF rejected');
check($db->query("SELECT last_login_at FROM admins")->fetchColumn() === '2026-11-01 10:00:00.000000', 'last login recorded in UTC');

// --- Categories -------------------------------------------------------------
$spices = status(write('POST', '/admin/categories', ['name' => 'MOCK Spices', 'slug' => 'mock-spices']), 201, 'create category')['data'];
check($spices['is_active'] === false && $spices['description'] === null && $spices['sort_order'] === 0, 'category defaults');
status(write('POST', '/admin/categories', ['name' => 'Dup', 'slug' => 'mock-spices']), 409, 'duplicate category slug');
status(write('POST', '/admin/categories', ['name' => '   ', 'slug' => 'Bad Slug', 'sort_order' => -1]), 422, 'invalid category');
status(write('PATCH', '/admin/categories/' . $spices['id'], []), 422, 'empty category patch');
status(write('PATCH', '/admin/categories/999999', ['is_active' => true]), 404, 'missing category');
status(write('PATCH', '/admin/categories/abc', ['is_active' => true]), 422, 'invalid id');
$spices = status(write('PATCH', '/admin/categories/' . $spices['id'], ['is_active' => true, 'description' => 'MOCK description']), 200, 'activate category')['data'];
check($spices['is_active'] === true, 'category activated');
$other = status(write('POST', '/admin/categories', ['name' => 'MOCK Other', 'slug' => 'mock-other', 'is_active' => false, 'sort_order' => 5]), 201, 'second category')['data'];
check(count(status(call('GET', '/admin/categories'), 200, 'admin categories')['data']) === 2, 'admin sees inactive categories');
check(count(status(call('GET', '/categories'), 200, 'public categories')['data']) === 1, 'public sees active only');

// --- Products ---------------------------------------------------------------
status(write('POST', '/admin/products', ['name' => 'MOCK Chilli', 'slug' => 'mock-chilli', 'category_id' => 999]), 422, 'unknown category');
status(write('POST', '/admin/products', ['name' => 'MOCK Chilli', 'slug' => 'mock-chilli', 'category_id' => '1']), 422, 'string id rejected');
status(write('POST', '/admin/products', ['name' => 'MOCK Chilli', 'slug' => 'mock-chilli', 'category_id' => $spices['id'], 'status' => 'published']), 422, 'status not accepted on create');
status(call('POST', '/admin/products', null, ['x-csrf-token' => token(), 'content-type' => 'application/json']), 400, 'empty body');
try {
    $kernel->handle(new Request('POST', '/api/v1/admin/products', [], ['x-csrf-token' => token(), 'content-type' => 'application/json'], '{"name":'));
    check(false, 'malformed JSON rejected');
} catch (ApiException $error) {
    check($error->status === 400, 'malformed JSON rejected');
}
$product = status(write('POST', '/admin/products', ['name' => 'MOCK Chilli 100%', 'slug' => 'mock-chilli', 'category_id' => $spices['id'], 'short_description' => 'MOCK short']), 201, 'create product')['data'];
check($product['status'] === 'draft' && $product['images'] === [] && $product['pack_size_label'] === null, 'product created as draft');
status(write('POST', '/admin/products', ['name' => 'Dup', 'slug' => 'mock-chilli', 'category_id' => $spices['id']]), 409, 'duplicate product slug');
$pid = $product['id'];
status(call('GET', "/admin/products/$pid"), 200, 'admin sees draft');
status(call('GET', '/products/mock-chilli'), 404, 'public cannot see draft');
$publish = status(write('POST', "/admin/products/$pid/status", ['status' => 'published']), 422, 'publish blocked');
$fields = (array) $publish['error']['fields'];
check(isset($fields['description'], $fields['images']), 'publish problems listed');
status(write('PATCH', "/admin/products/$pid", ['description' => 'MOCK full description', 'pack_size_label' => '100 g MOCK']), 200, 'patch draft');

// --- Images -----------------------------------------------------------------
$first = status(send($pid, image('jpeg', 40, 30, '<?php echo "pwned"; ?>')), 201, 'upload jpeg polyglot')['data'];
check(preg_match('#^/uploads/products/[a-f0-9]{32}\.jpg$#', $first['url']) === 1 && $first['sort_order'] === 0, 'generated public URL');
$stored = $uploads . '/' . basename($first['url']);
check(is_file($stored) && !str_contains(file_get_contents($stored), '<?php'), 're-encoding strips appended payload');
check(decoct(fileperms($stored) & 0777) === '644', 'upload not executable');
$second = status(send($pid, image('png'), ''), 201, 'upload png without alt')['data'];
$third = status(send($pid, image('webp')), 201, 'upload webp')['data'];
check($third['sort_order'] === 2, 'appended to gallery');
$php = tempnam(sys_get_temp_dir(), 'spicemama-bad');
file_put_contents($php, '<?php phpinfo();');
status(send($pid, upload($php)), 415, 'PHP file rejected');
$svg = tempnam(sys_get_temp_dir(), 'spicemama-bad');
file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
status(send($pid, upload($svg)), 415, 'SVG rejected');
status(send($pid, image('png', 4097, 1)), 422, 'side limit');
status(send($pid, image('png', 2001, 2000)), 422, 'pixel limit');
$apngSource = image('png')['file']['tmp_name'];
$png = file_get_contents($apngSource);
$actl = pack('N', 8) . 'acTL' . pack('NN', 1, 0) . pack('N', crc32('acTL' . pack('NN', 1, 0)));
file_put_contents($apngSource, substr($png, 0, 33) . $actl . substr($png, 33));
status(send($pid, upload($apngSource)), 422, 'animated PNG rejected');
$truncated = image('jpeg')['file']['tmp_name'];
file_put_contents($truncated, substr(file_get_contents($truncated), 0, 200));
status(send($pid, upload($truncated)), 422, 'corrupt JPEG rejected');
$big = image('jpeg');
$big['file']['size'] = 5242881;
status(send($pid, $big), 413, 'size limit');
status(send($pid, ['file' => ['error' => UPLOAD_ERR_INI_SIZE, 'size' => 0, 'tmp_name' => '', 'uploaded' => false]]), 413, 'ini size limit');
status(send($pid, []), 422, 'file required');
status(send(999999, image('jpeg')), 422, 'upload to missing product');
status(call('POST', '/admin/images', ['product_id' => $pid], ['x-csrf-token' => token()]), 415, 'upload must be multipart');
$forged = image('jpeg');
$forged['file']['uploaded'] = false;
status(send($pid, $forged), 422, 'non-uploaded file path rejected');
check(count(glob($uploads . '/*')) === 3, 'rejected uploads leave no files');
check(glob($root . '/storage/upload-staging/*') === [], 'staging cleaned');

// --- Publication ------------------------------------------------------------
$published = status(write('POST', "/admin/products/$pid/status", ['status' => 'published']), 200, 'publish')['data'];
check($published['status'] === 'published', 'published');
$publishedAt = $db->query("SELECT published_at FROM products WHERE id=$pid")->fetchColumn();
$public = status(call('GET', '/products/mock-chilli'), 200, 'public detail')['data'];
check(array_column($public['images'], 'id') === [$first['id'], $second['id'], $third['id']], 'public gallery order');
check(status(call('GET', '/products', null, [], ['q' => '100%']), 200, 'search')['meta']['total'] === 1, 'literal search');
status(write('PATCH', "/admin/products/$pid", ['description' => '  ']), 422, 'published edit keeps rules');
status(write('PATCH', "/admin/products/$pid", ['category_id' => $other['id']]), 422, 'cannot move published product to inactive category');
check(status(call('GET', "/admin/products/$pid"), 200, 'reload')['data']['description'] === 'MOCK full description', 'failed edit rolled back');
$now = $now->modify('+1 minute');
status(write('POST', "/admin/products/$pid/status", ['status' => 'published']), 200, 'repeat status is no-op');
status(write('POST', "/admin/products/$pid/status", ['status' => 'draft']), 200, 'unpublish');
status(call('GET', '/products/mock-chilli'), 404, 'unpublished hidden');
status(write('POST', "/admin/products/$pid/status", ['status' => 'published']), 200, 'republish');
check($db->query("SELECT published_at FROM products WHERE id=$pid")->fetchColumn() === $publishedAt, 'first publication time kept');
status(write('POST', "/admin/products/999999/status", ['status' => 'draft']), 404, 'status missing product');
status(write('POST', "/admin/products/$pid/status", ['status' => 'deleted']), 422, 'invalid status');

// Category deactivation hides without changing status, reactivation restores.
status(write('PATCH', '/admin/categories/' . $spices['id'], ['is_active' => false]), 200, 'deactivate category');
status(call('GET', '/products/mock-chilli'), 404, 'inactive category hides product');
check(status(call('GET', "/admin/products/$pid"), 200, 'admin view')['data']['status'] === 'published', 'status unchanged by deactivation');
status(write('PATCH', '/admin/categories/' . $spices['id'], ['is_active' => true]), 200, 'reactivate');
status(call('GET', '/products/mock-chilli'), 200, 'reactivation restores');

// --- Gallery maintenance ----------------------------------------------------
status(write('PUT', "/admin/products/$pid/images/order", ['image_ids' => [$third['id'], $first['id']]]), 409, 'incomplete order');
status(write('PUT', "/admin/products/$pid/images/order", ['image_ids' => [$third['id'], $first['id'], $first['id']]]), 422, 'duplicate ids');
status(write('PUT', "/admin/products/$pid/images/order", ['image_ids' => [$third['id'], $first['id'], 999999]]), 409, 'foreign id');
status(write('PUT', '/admin/products/999999/images/order', ['image_ids' => []]), 404, 'reorder missing product');
$reordered = status(write('PUT', "/admin/products/$pid/images/order", ['image_ids' => [$third['id'], $first['id'], $second['id']]]), 200, 'reorder')['data'];
check(array_column($reordered['images'], 'id') === [$third['id'], $first['id'], $second['id']] && array_column($reordered['images'], 'sort_order') === [0, 1, 2], 'reordered and normalised');
check($db->query("SELECT id FROM product_images WHERE is_primary=1")->fetchAll(PDO::FETCH_COLUMN) == [$third['id']], 'first image is sole primary');
status(write('PATCH', '/admin/images/' . $first['id'], ['alt_text' => '']), 200, 'blank alt allowed while another has alt');
status(write('PATCH', '/admin/images/' . $third['id'], ['alt_text' => ' ']), 422, 'last described image keeps alt');
status(write('PATCH', '/admin/images/999999', ['alt_text' => 'x']), 404, 'missing image');
status(write('DELETE', '/admin/images/' . $second['id']), 204, 'delete undescribed image');
status(write('DELETE', '/admin/images/' . $third['id']), 409, 'cannot remove last described image of published product');
status(write('PATCH', '/admin/images/' . $first['id'], ['alt_text' => 'MOCK restored']), 200, 'restore alt');
$thirdFile = $uploads . '/' . basename($third['url']);
status(write('DELETE', '/admin/images/' . $third['id']), 204, 'delete primary');
check(!is_file($thirdFile), 'file removed after commit');
$remaining = status(call('GET', "/admin/products/$pid"), 200, 'reload')['data']['images'];
check(count($remaining) === 1 && $remaining[0]['id'] === $first['id'] && $remaining[0]['sort_order'] === 0, 'next image promoted');
check($db->query("SELECT is_primary FROM product_images WHERE id=" . $first['id'])->fetchColumn() == 1, 'promoted primary flag');
status(write('DELETE', '/admin/images/' . $first['id']), 409, 'cannot remove final image of published product');
status(write('DELETE', '/admin/images/' . $second['id']), 404, 'deleted image is gone');
status(write('POST', "/admin/products/$pid/status", ['status' => 'archived']), 200, 'archive');
status(write('DELETE', '/admin/images/' . $first['id']), 204, 'archived product may empty gallery');
check(status(write('PUT', "/admin/products/$pid/images/order", ['image_ids' => []]), 200, 'empty order for empty gallery')['data']['images'] === [], 'empty gallery');

// --- Admin listing ----------------------------------------------------------
status(write('POST', '/admin/products', ['name' => 'MOCK Draft two', 'slug' => 'mock-draft-two', 'category_id' => $other['id'], 'sort_order' => 1]), 201, 'second product');
$list = status(call('GET', '/admin/products', null, [], ['per_page' => '1']), 200, 'admin list');
check($list['meta'] === ['page' => 1, 'per_page' => 1, 'total' => 2, 'total_pages' => 2], 'admin pagination');
check(status(call('GET', '/admin/products', null, [], ['status' => 'archived']), 200, 'status filter')['meta']['total'] === 1, 'status filter count');
check(status(call('GET', '/admin/products', null, [], ['category' => 'mock-other']), 200, 'inactive category filter')['meta']['total'] === 1, 'admin category filter includes inactive');
status(call('GET', '/admin/products', null, [], ['status' => 'gone']), 422, 'bad status filter');
check(status(call('GET', '/products'), 200, 'public list')['meta']['total'] === 0, 'public list excludes archived/draft');

// --- Audit ------------------------------------------------------------------
$actions = $db->query('SELECT action FROM admin_audit_logs ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
foreach (['admin.provisioned', 'admin.login', 'category.created', 'product.created', 'product.status', 'image.uploaded', 'image.reordered', 'image.removed'] as $action) {
    check(in_array($action, $actions, true), "audit $action");
}
check(!str_contains(implode(' ', $db->query('SELECT COALESCE(details_json,"") FROM admin_audit_logs')->fetchAll(PDO::FETCH_COLUMN)), 'MOCK full'), 'audit stores field names, not content');

// --- Locking ----------------------------------------------------------------
$other = Database::connect($config['database']);
$other->beginTransaction();
$other->query("SELECT id FROM products WHERE id=$pid FOR UPDATE");
$db->exec('SET SESSION innodb_lock_wait_timeout=1');
status(write('PUT', "/admin/products/$pid/images/order", ['image_ids' => []]), 503, 'locked product returns retryable 503');
$other->rollBack();
check(!$db->inTransaction(), 'contention rolled back');
$db->exec('SET SESSION innodb_lock_wait_timeout=50');

// --- Sessions ---------------------------------------------------------------
$now = $now->modify('+31 minutes');
status(call('GET', '/admin/me'), 401, 'idle expiry');
check($session->store === [], 'expired session cleared');
$login = fn(string $email, string $pass, string $ip = '127.0.0.1') => call('POST', '/admin/login', ['email' => $email, 'password' => $pass], ['x-csrf-token' => token()], [], [], [], $ip);
status($login('owner@example.test', $password), 200, 'login again');
$db->exec("UPDATE admins SET is_active=0");
status(call('GET', '/admin/me'), 401, 'deactivated admin loses session');
status($login('owner@example.test', $password), 401, 'inactive admin cannot sign in');
$db->exec("UPDATE admins SET is_active=1");
status($login('owner@example.test', $password), 200, 'reactivated');
status(write('POST', '/admin/logout'), 204, 'logout');
status(call('GET', '/admin/me'), 401, 'logged out');
status(call('POST', '/admin/logout', null, ['x-csrf-token' => token()]), 401, 'logout requires session');

// --- Throttling -------------------------------------------------------------
for ($i = 0; $i < 5; ++$i) {
    status($login('owner@example.test', 'wrong'), 401, "failure $i");
}
$blocked = $login('owner@example.test', $password);
status($blocked, 429, 'account throttled even with correct password');
check((int) $blocked->headers['Retry-After'] >= 1 && (int) $blocked->headers['Retry-After'] <= 900, 'Retry-After seconds');
status($login('owner@example.test', $password, '10.0.0.9'), 429, 'account bucket independent of client');
check($db->query('SELECT COUNT(*) FROM login_throttles')->fetchColumn() >= 2, 'throttle rows persisted');
check(!str_contains(implode(' ', $db->query('SELECT bucket_key FROM login_throttles')->fetchAll(PDO::FETCH_COLUMN)), 'owner'), 'throttle keys are hashed');
$now = $now->modify('+16 minutes');
status($login('owner@example.test', $password), 200, 'block expires');
for ($i = 0; $i < 20; ++$i) {
    $login("spray$i@example.test", 'wrong', '10.0.0.5');
}
status($login('owner@example.test', $password, '10.0.0.5'), 429, 'client bucket throttles password spraying');

// --- Hostile input ----------------------------------------------------------
status(call('GET', "/admin/products/1%20OR%201=1"), 422, 'path injection rejected');
status(write('POST', '/admin/categories', ['name' => "Robert'); DROP TABLE products;--", 'slug' => 'mock-bobby']), 201, 'SQL text stored literally');
check((int) $db->query("SELECT COUNT(*) FROM products")->fetchColumn() === 2, 'products table intact');
status(write('POST', '/admin/categories', ['name' => '<img src=x onerror=alert(1)>', 'slug' => 'mock-xss']), 201, 'markup stored as plain text');
status(write('POST', '/admin/products', ['name' => 'X', 'slug' => 'x', 'category_id' => 9007199254740992]), 422, 'unsafe integer rejected');
status(call('GET', '/admin/unknown'), 404, 'unknown admin route');
status(call('DELETE', '/admin/products/1', null, ['x-csrf-token' => token()]), 404, 'no product delete');

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
    $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
}
rmdir($root);
echo "PASS: $checks integration checks on MariaDB " . $db->query('SELECT VERSION()')->fetchColumn() . " ($database).\n";
