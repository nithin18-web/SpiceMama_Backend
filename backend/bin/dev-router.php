<?php
declare(strict_types=1);

// Local use only: never deploy this router. PHP built-in server must bind loopback.
$path = rawurldecode(explode('?', $_SERVER['REQUEST_URI'] ?? '/', 2)[0]);
if ($path === '/api/v1' || str_starts_with($path, '/api/v1/')) {
    require dirname(__DIR__) . '/public/api/index.php';
    return true;
}
$types = ['html' => 'text/html; charset=utf-8', 'css' => 'text/css', 'js' => 'text/javascript', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2', 'txt' => 'text/plain; charset=utf-8', 'xml' => 'application/xml'];
$root = realpath(dirname(__DIR__, 2) . '/frontend');
$file = $root === false ? false : realpath($root . $path);
// Directory requests (/, /admin/) serve their index.html, like Apache DirectoryIndex.
if ($file !== false && is_dir($file)) {
    $file = realpath($file . '/index.html');
}
if ($file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file) ||
    !isset($types[strtolower(pathinfo($file, PATHINFO_EXTENSION))])) {
    http_response_code(404);
    // Mirrors production `ErrorDocument 404 /404.html`.
    if (is_file($root . '/404.html')) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($root . '/404.html');
    } else {
        echo 'Not found';
    }
    return true;
}
header('Content-Type: ' . $types[strtolower(pathinfo($file, PATHINFO_EXTENSION))]);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
readfile($file);
return true;
