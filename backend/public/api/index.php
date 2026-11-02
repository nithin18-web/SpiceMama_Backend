<?php
declare(strict_types=1);

// Deployment: set SPICEMAMA_APP_PATH to /home/spicemam/app outside public_html.
$app = getenv('SPICEMAMA_APP_PATH') ?: dirname(__DIR__, 2);
ini_set('display_errors', '0');
error_reporting(E_ALL);
require $app . '/bootstrap/autoload.php';

use SpiceMama\Http\{ApiException, Kernel, Request, Response};
use SpiceMama\Services\NativeSession;
use SpiceMama\Support\{Clock, Config, Database};

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    if (PHP_VERSION_ID < 80400 || PHP_VERSION_ID >= 80500 || !extension_loaded('mbstring')) {
        throw new ApiException(503, 'unavailable', 'Service is not configured.');
    }
    try {
        $config = Config::load(Config::path($app));
    } catch (Throwable $error) {
        error_log('SpiceMama configuration invalid: ' . get_class($error));
        throw new ApiException(503, 'unavailable', 'Service is not configured.');
    }
    $request = Request::fromGlobals();
    $secure = ($config['secure_cookie'] ?? true) !== false;
    // Plain-HTTP session cookies are a loopback development exception only.
    if (!$secure && !in_array($request->clientIp, ['127.0.0.1', '::1'], true)) {
        throw new ApiException(503, 'unavailable', 'Service is not configured.');
    }
    $kernel = new Kernel(
        static function () use ($config): PDO {
            try {
                return Database::connect($config['database'] ?? []);
            } catch (Throwable $error) {
                // Log category only; exception messages may include connection secrets.
                error_log('SpiceMama database unavailable: ' . get_class($error));
                throw new ApiException(503, 'unavailable', 'Service is temporarily unavailable.');
            }
        },
        new NativeSession(Kernel::storage($config, 'sessions'), $secure),
        new Clock(),
        $config,
    );
    $response = $kernel->handle($request);
} catch (ApiException $error) {
    $response = Response::error($error);
} catch (Throwable $error) {
    error_log('SpiceMama request failed: ' . get_class($error) . ($error instanceof PDOException ? ' ' . $error->getCode() : ''));
    $response = Response::error(new ApiException(500, 'internal_error', 'Unable to process this request.'));
}
$response->send();
