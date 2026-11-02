<?php
declare(strict_types=1);

// Copy to config/local.php (gitignored) for a dedicated LOCAL database only.
// This file must remain outside public_html. Environment values are not logged.
// Production config is written on the server, never copied from a developer machine.
return [
    'database' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: '3306',
        'database' => getenv('DB_DATABASE') ?: '',
        'username' => getenv('DB_USERNAME') ?: '',
        'password' => getenv('DB_PASSWORD') ?: '',
    ],
    // Private, writable, absolute: sessions/ and upload-staging/ are created inside.
    'storage_path' => getenv('PRIVATE_STORAGE_PATH') ?: '',
    // Public directory served at /uploads/products/ (locally: frontend/uploads/products).
    'upload_path' => getenv('PRODUCT_UPLOAD_PATH') ?: '',
    // At least 32 random characters (e.g. `openssl rand -hex 32`); keys login-throttle HMACs.
    'throttle_key' => getenv('LOGIN_THROTTLE_KEY') ?: '',
    // false is honoured only for loopback clients; production must keep true (HTTPS).
    'secure_cookie' => true,
];
