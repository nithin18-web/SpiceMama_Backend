<?php
// TEMPORARY: upload to public_html, open https://<host>/server-check.php once, then DELETE it.
// Reports only yes/no capability flags; no paths, settings values or secrets.
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
$gd = function_exists('gd_info') ? gd_info() : [];
$app = dirname(__DIR__) . '/app';
$checks = [
    'PHP 8.4' => PHP_VERSION_ID >= 80400 && PHP_VERSION_ID < 80500,
    'pdo_mysql' => extension_loaded('pdo_mysql'),
    'mbstring' => extension_loaded('mbstring'),
    'fileinfo' => class_exists('finfo'),
    'GD JPEG' => !empty($gd['JPEG Support']),
    'GD PNG' => !empty($gd['PNG Support']),
    'GD WebP' => !empty($gd['WebP Support']),
    'private app folder found' => is_file($app . '/bootstrap/autoload.php'),
    'private config present' => is_file($app . '/config/local.php'),
    'uploads folder writable' => is_writable(__DIR__ . '/uploads/products'),
];
foreach ($checks as $name => $ok) {
    echo ($ok ? 'OK    ' : 'FAIL  ') . $name . "\n";
}
