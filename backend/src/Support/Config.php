<?php
declare(strict_types=1);
namespace SpiceMama\Support;

final class Config
{
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
