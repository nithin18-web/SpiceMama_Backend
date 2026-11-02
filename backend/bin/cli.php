<?php
declare(strict_types=1);

// Shared CLI bootstrap. Never reachable from the web (bin/ stays private).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$app = getenv('SPICEMAMA_APP_PATH') ?: dirname(__DIR__);
require $app . '/bootstrap/autoload.php';
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

function cli_config(string $app): array
{
    return SpiceMama\Support\Config::load(SpiceMama\Support\Config::path($app));
}

function cli_db(array $config): PDO
{
    try {
        return SpiceMama\Support\Database::connect($config['database'] ?? []);
    } catch (Throwable $error) {
        // Exception messages can contain connection details.
        fwrite(STDERR, 'Database connection failed (' . get_class($error) . "). Check private configuration.\n");
        exit(1);
    }
}

function cli_fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}
