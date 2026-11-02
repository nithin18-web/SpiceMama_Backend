<?php
declare(strict_types=1);
namespace SpiceMama\Support;

final class Config
{
    /** SPICEMAMA_CONFIG is server-set only (tests use it); defaults to the private config/local.php. */
    public static function path(string $app): string
    {
        return getenv('SPICEMAMA_CONFIG') ?: $app . '/config/local.php';
    }

    /** Private PHP config is outside the web root and contains no executable input from clients. */
    public static function load(string $path): array
    {
        $config = is_file($path) ? require $path : [];
        if (!is_array($config)) {
            throw new \RuntimeException('Invalid private configuration.');
        }
        return $config;
    }
}
