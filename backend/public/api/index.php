<?php
declare(strict_types=1);

// Deployment: set SPICEMAMA_APP_PATH to /home/spicemam/app outside public_html.
$app = getenv('SPICEMAMA_APP_PATH') ?: dirname(__DIR__, 2);
ini_set('display_errors', '0');
error_reporting(E_ALL);
require $app . '/bootstrap/autoload.php';

use SpiceMama\Controllers\CatalogueController;
use SpiceMama\Http\{ApiException, Request, Response};
use SpiceMama\Repositories\PdoCatalogueRepository;
use SpiceMama\Support\{Config, Database};

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
    $controller = new CatalogueController(static function () use ($app): PdoCatalogueRepository {
        try {
            return new PdoCatalogueRepository(Database::connect(Config::load($app . '/config/local.php')['database'] ?? []));
        } catch (Throwable $error) {
            // Log category only; exception messages may include connection secrets.
            error_log('SpiceMama database unavailable: ' . get_class($error));
            throw new ApiException(503, 'unavailable', 'Catalogue is temporarily unavailable.');
        }
    });
    $response = $controller->handle(Request::fromGlobals());
} catch (ApiException $error) {
    $response = Response::error($error);
} catch (Throwable $error) {
    error_log('SpiceMama request failed: ' . get_class($error));
    $response = Response::error(new ApiException(500, 'internal_error', 'Unable to process this request.'));
}
$response->send();
