<?php
declare(strict_types=1);

// Local use only: never deploy this router. PHP built-in server must bind loopback.
$path = rawurldecode(explode('?', $_SERVER['REQUEST_URI'] ?? '/', 2)[0]);
if ($path === '/api/v1' || str_starts_with($path, '/api/v1/')) {
    require dirname(__DIR__) . '/public/api/index.php';
    return true;
}
$root = realpath(dirname(__DIR__, 2) . '/frontend');
$file = $root === false ? false : realpath($root . ($path === '/' ? '/index.html' : $path));
if ($file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file) ||
    !in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['html', 'css', 'js', 'jpg', 'jpeg', 'png', 'webp', 'svg', 'ico', 'woff', 'woff2'], true)) {
    http_response_code(404);
    echo 'Not found';
    return true;
}
$types = ['html' => 'text/html; charset=utf-8', 'css' => 'text/css', 'js' => 'text/javascript', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2'];
header('Content-Type: ' . $types[strtolower(pathinfo($file, PATHINFO_EXTENSION))]);
header('X-Content-Type-Options: nosniff');
readfile($file);
return true;
