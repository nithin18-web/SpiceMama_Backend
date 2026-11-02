<?php
declare(strict_types=1);

// PRODUCTION config for VapourHost. On the server, save as /home/spicemam/app/config/local.php
// (outside public_html) and replace the three CHANGE_ME values with the database you create
// in Webuzo (Database > Add Database / Add Database User). Never commit the filled-in file.
return [
    'database' => [
        'host' => 'localhost',
        'port' => '3306',
        'database' => 'CHANGE_ME_DATABASE_NAME',     // e.g. spicemam_site
        'username' => 'CHANGE_ME_DATABASE_USER',     // e.g. spicemam_app
        'password' => 'CHANGE_ME_DATABASE_PASSWORD',
    ],
    // Private: sessions, upload staging and the generated login-throttle key.
    'storage_path' => '/home/spicemam/storage-private',
    // Public product photos, served as https://spicemama.in/uploads/products/...
    'upload_path' => '/home/spicemam/public_html/uploads/products',
    // HTTPS-only session cookie. Never set to false on the server.
    'secure_cookie' => true,
];
