<?php
declare(strict_types=1);

// Copy to config/local.php only when a dedicated LOCAL database is approved.
// This file must remain outside public_html. Environment values are not logged.
return ['database' => [
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'port' => getenv('DB_PORT') ?: '3306',
    'database' => getenv('DB_DATABASE') ?: '',
    'username' => getenv('DB_USERNAME') ?: '',
    'password' => getenv('DB_PASSWORD') ?: '',
]];
