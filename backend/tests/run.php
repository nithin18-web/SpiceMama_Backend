<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap/autoload.php';

use SpiceMama\Controllers\CatalogueController;
use SpiceMama\Http\{ApiException, Request, Response};
use SpiceMama\Repositories\PdoCatalogueRepository;
use SpiceMama\Services\SessionSecurity;
use SpiceMama\Validation\CatalogueQuery;

$checks = 0;
function check(bool $condition, string $label): void
{
    global $checks;
    ++$checks;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $label);
    }
}
function rejects(Closure $action, int $status, string $label): void
{
    try {
        $action();
    } catch (ApiException $error) {
        check($error->status === $status, $label);
        return;
    }
    throw new RuntimeException('FAIL (accepted): ' . $label);
}
check(PHP_VERSION_ID >= 80400 && PHP_VERSION_ID < 80500, 'PHP 8.4');
check(CatalogueQuery::parse([])['per_page'] === 12, 'default pagination');
check(CatalogueQuery::parse(['q' => '  Test  ', 'page' => '2'])['q'] === 'Test', 'trim search');
foreach (['0', '-1', '1.5', '1e2', '1000001', '99999999999999999999'] as $value) {
    rejects(fn() => CatalogueQuery::parse(['page' => $value]), 422, 'reject page ' . $value);
}
rejects(fn() => CatalogueQuery::parse(['per_page' => '51']), 422, 'page bound');
rejects(fn() => CatalogueQuery::parse(['q' => str_repeat('界', 101)]), 422, 'Unicode character bound');
rejects(fn() => CatalogueQuery::parse(['q' => "\xff"]), 400, 'invalid UTF-8');
rejects(fn() => CatalogueQuery::parse(['category' => ['x']]), 400, 'array parameter');
rejects(fn() => CatalogueQuery::parse(['secret' => 'x']), 422, 'unknown query');
rejects(fn() => CatalogueQuery::slug("x' OR 1=1 --"), 422, 'slug injection');

foreach (['page=1&page=2', 'page[]=1', 'q=%ZZ'] as $raw) {
    $_SERVER['REQUEST_URI'] = '/api/v1/products?' . $raw;
    rejects(fn() => Request::fromGlobals(), 400, 'ambiguous query rejected');
}
$_SERVER['REQUEST_URI'] = '/api/v1/products?q=Test+Name';
check(Request::fromGlobals()->query['q'] === 'Test Name', 'URL query decoding');

$session = [];
$token = SessionSecurity::token($session);
check(strlen($token) === 64 && SessionSecurity::token($session) === $token, 'stable random CSRF');
SessionSecurity::verifyCsrf($session, $token);
rejects(fn() => SessionSecurity::verifyCsrf($session, null), 403, 'missing CSRF');
rejects(fn() => SessionSecurity::verifyCsrf($session, str_repeat('0', 64)), 403, 'wrong CSRF');
$session = ['admin_id' => 1, 'authenticated_at' => 100, 'last_seen' => 200];
check(SessionSecurity::authenticated($session, 300) === 1 && $session['last_seen'] === 300, 'session refresh');
rejects(function () use (&$session) { SessionSecurity::authenticated($session, 2100); }, 401, 'idle expiration');
check($session === [], 'expired session cleared');
$session = ['admin_id' => 1, 'authenticated_at' => 100, 'last_seen' => 28899];
rejects(function () use (&$session) { SessionSecurity::authenticated($session, 28900); }, 401, 'absolute expiration');

// Ephemeral SQLite fixtures exercise repository behavior, not MariaDB compatibility.
// No project/production database or proposed migration SQL is used here.
$db = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec('CREATE TABLE categories(id INTEGER PRIMARY KEY,name TEXT,slug TEXT,description TEXT,sort_order INTEGER,is_active INTEGER)');
$db->exec('CREATE TABLE products(id INTEGER PRIMARY KEY,slug TEXT,name TEXT,category_id INTEGER,short_description TEXT,description TEXT,pack_size_label TEXT,ingredients TEXT,status TEXT,sort_order INTEGER)');
$db->exec('CREATE TABLE product_images(id INTEGER PRIMARY KEY,product_id INTEGER,file_path TEXT,alt_text TEXT,sort_order INTEGER)');
$db->exec("INSERT INTO categories VALUES(1,'MOCK active','mock-active',NULL,0,1),(2,'MOCK inactive','mock-inactive',NULL,0,0)");
$insert = $db->prepare('INSERT INTO products VALUES(?,?,?,?,?,?,?,?,?,?)');
foreach ([[1,'mock-public','MOCK 100%_!',1,'published'],[2,'mock-draft','MOCK draft',1,'draft'],[3,'mock-archived','MOCK archived',1,'archived'],[4,'mock-hidden','MOCK hidden',2,'published'],[5,'mock-second','MOCK second',1,'published']] as [$id,$slug,$name,$category,$status]) {
    $insert->execute([$id,$slug,$name,$category,'MOCK description','MOCK description',null,null,$status,0]);
}
$db->exec("INSERT INTO product_images VALUES(2,1,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.webp','MOCK second image',1),(1,1,'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.jpg','MOCK primary image',0)");
$repository = new PdoCatalogueRepository($db);
$controller = new CatalogueController(fn() => $repository);
$result = $controller->handle(new Request('GET', '/api/v1/products'))->body;
check(array_column($result['data'], 'id') === [1,5], 'only public products with active category, stable order');
check($result['meta'] === ['page'=>1,'per_page'=>12,'total'=>2,'total_pages'=>1], 'pagination response');
check($result['data'][0]['images'][0]['id'] === 1, 'gallery order');
check($result['data'][0]['images'][0]['url'] === '/uploads/products/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.jpg', 'safe public image URL');
check($result['data'][0]['ingredients'] === null, 'nullable field preserved');
foreach (['mock-draft','mock-archived','mock-hidden','missing'] as $slug) {
    rejects(fn() => $controller->handle(new Request('GET', '/api/v1/products/' . $slug)), 404, 'hidden slug ' . $slug);
}
check($controller->handle(new Request('GET','/api/v1/products',['q'=>'%_!']))->body['meta']['total'] === 1, 'literal search wildcards');
check($controller->handle(new Request('GET','/api/v1/products',['q'=>"' OR 1=1 --"]))->body['meta']['total'] === 0, 'bound search injection');
check($controller->handle(new Request('GET','/api/v1/products',['category'=>'mock-inactive']))->body['data'] === [], 'inactive category filter');
check($controller->handle(new Request('GET','/api/v1/products',['page'=>'3','per_page'=>'1']))->body['data'] === [], 'out-of-range page');
check($controller->handle(new Request('GET','/api/v1/products',['per_page'=>'1']))->body['meta']['total_pages'] === 2, 'multiple pages');
check(count($controller->handle(new Request('GET','/api/v1/categories'))->body['data']) === 1, 'active categories only');
check(is_bool($repository->categories()[0]['is_active']), 'JSON boolean type');
rejects(fn() => $controller->handle(new Request('POST','/api/v1/products')),404,'public write disabled');
rejects(fn() => $controller->handle(new Request('POST','/api/v1/admin/login')),404,'unfinished admin not exposed');
rejects(fn() => $controller->handle(new Request('GET','/api/v1/categories',['q'=>'x'])),422,'category unknown query');
$db->exec("UPDATE product_images SET file_path='../secret' WHERE id=1");
try {
    $repository->product('mock-public');
    throw new RuntimeException('FAIL: unsafe metadata accepted');
} catch (RuntimeException $error) {
    check($error->getMessage() === 'Unsafe image metadata.', 'reject unsafe image paths');
    check(!$db->inTransaction(), 'repository rollback after metadata error');
}
check(Response::error(new ApiException(422,'validation_failed','Invalid',['q'=>'Too long']))->body['error']['code'] === 'validation_failed','error envelope');
echo "PASS: $checks checks. SQLite fixtures only; MariaDB integration pending SQL approval.\n";
