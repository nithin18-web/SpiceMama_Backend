<?php
declare(strict_types=1);
namespace SpiceMama\Http;
use PDO;
use SpiceMama\Controllers\{AdminAuthController, AdminCategoryController, AdminImageController, AdminProductController, AdminSiteController, CatalogueController};
use SpiceMama\Middleware\AdminGuard;
use SpiceMama\Repositories\PdoCatalogueRepository;
use SpiceMama\Services\{ImageStore, LoginThrottle, SessionStore};
use SpiceMama\Support\{Clock, Db};

/** Routes /api/v1 requests. Public catalogue routes never start a session. */
final class Kernel
{
    private ?PDO $pdo = null;

    /** @param \Closure():PDO $connect throws ApiException(503) when unavailable */
    public function __construct(
        private readonly \Closure $connect,
        private readonly SessionStore $session,
        private readonly Clock $clock,
        private readonly array $config,
    ) {}

    public function handle(Request $request): Response
    {
        try {
            if ($request->path !== '/api/v1/admin' && !str_starts_with($request->path, '/api/v1/admin/')) {
                return (new CatalogueController(fn(): PdoCatalogueRepository => new PdoCatalogueRepository($this->db())))->handle($request);
            }
            return $this->admin($request);
        } catch (\PDOException $error) {
            if (Db::isContention($error)) {
                throw new ApiException(503, 'unavailable', 'The record is busy. Reload and try again.');
            }
            throw $error;
        }
    }

    private function admin(Request $request): Response
    {
        $db = fn(): PDO => $this->db();
        $guard = new AdminGuard($this->session, $db, $this->clock);
        $auth = new AdminAuthController($guard, $db, $this->clock, fn(): LoginThrottle => new LoginThrottle($this->db(), self::throttleKey($this->config), $this->clock));
        $categories = new AdminCategoryController($guard, $db, $this->clock);
        $products = new AdminProductController($guard, $db, $this->clock);
        $images = new AdminImageController($guard, $db, $this->clock, fn(): ImageStore => new ImageStore(
            (string) ($this->config['upload_path'] ?? ''),
            self::storage($this->config, 'upload-staging'),
        ));
        $site = new AdminSiteController($guard, $db, $this->clock, $this->config);
        $id = '([^/]+)';
        $routes = [
            ['GET', '/csrf', $auth->csrf(...)],
            ['POST', '/login', $auth->login(...)],
            ['GET', '/me', $auth->me(...)],
            ['POST', '/logout', $auth->logout(...)],
            ['POST', '/launch', $site->launch(...)],
            ['GET', '/products', $products->list(...)],
            ['POST', '/products', $products->create(...)],
            ['GET', "/products/$id", $products->show(...)],
            ['PATCH', "/products/$id", $products->update(...)],
            ['POST', "/products/$id/status", $products->status(...)],
            ['PUT', "/products/$id/images/order", $images->reorder(...)],
            ['GET', '/categories', $categories->list(...)],
            ['POST', '/categories', $categories->create(...)],
            ['PATCH', "/categories/$id", $categories->update(...)],
            ['POST', '/images', $images->upload(...)],
            ['PATCH', "/images/$id", $images->update(...)],
            ['DELETE', "/images/$id", $images->delete(...)],
        ];
        foreach ($routes as [$method, $pattern, $handler]) {
            if ($request->method === $method && preg_match('#^/api/v1/admin' . $pattern . '$#D', $request->path, $matches)) {
                return $handler($request, ...array_slice($matches, 1));
            }
        }
        throw new ApiException(404, 'not_found', 'Endpoint not found.');
    }

    /** Private storage subdirectory, or '' when storage is not configured (callers then return 503). */
    public static function storage(array $config, string $directory): string
    {
        $root = rtrim((string) ($config['storage_path'] ?? ''), '/');
        return $root === '' ? '' : $root . '/' . $directory;
    }

    /**
     * Configured key, else a random key generated once in private storage (mode 600),
     * so no secret has to be copied by hand during deployment. '' when storage is unset.
     */
    public static function throttleKey(array $config): string
    {
        $key = (string) ($config['throttle_key'] ?? '');
        $directory = self::storage($config, 'keys');
        if ($key !== '' || $directory === '') {
            return $key;
        }
        $file = $directory . '/login-throttle.key';
        if (!is_file($file)) {
            if (!is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            // 'x' mode: a concurrent first request cannot overwrite a key already written.
            $handle = @fopen($file, 'x');
            if ($handle !== false) {
                fwrite($handle, bin2hex(random_bytes(32)));
                fclose($handle);
                chmod($file, 0600);
            }
        }
        return trim((string) file_get_contents($file));
    }

    private function db(): PDO
    {
        return $this->pdo ??= ($this->connect)();
    }
}
