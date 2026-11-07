<?php
declare(strict_types=1);
namespace SpiceMama\Support;

final class Paths
{
    /** Absolute on Unix (/home/...) or Windows (C:\... or C:/...); relative paths are rejected as unconfigured. */
    public static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }
}
