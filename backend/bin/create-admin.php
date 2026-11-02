<?php
declare(strict_types=1);

// Usage: php bin/create-admin.php --email=owner@example.com --name="Display Name"
// Password is read from the terminal without echo, or from STDIN when piped.
// There are no default credentials and no admin-creation API.
require __DIR__ . '/cli.php';

use SpiceMama\Repositories\AdminRepository;
use SpiceMama\Support\{Clock, Db};
use SpiceMama\Validation\AdminInput;

$options = getopt('', ['email:', 'name:']);
$email = AdminInput::email((string) ($options['email'] ?? ''));
$name = trim((string) ($options['name'] ?? ''));
if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    cli_fail('Provide a valid --email.');
}
if ($name === '' || mb_strlen($name) > 120) {
    cli_fail('Provide --name of 1 to 120 characters.');
}
$tty = function_exists('posix_isatty') ? posix_isatty(STDIN) : stream_isatty(STDIN);
if ($tty) {
    fwrite(STDOUT, 'Password (min 12 characters): ');
    shell_exec('stty -echo');
}
$password = rtrim((string) fgets(STDIN), "\r\n");
if ($tty) {
    shell_exec('stty echo');
    fwrite(STDOUT, "\n");
}
if (strlen($password) < 12 || mb_strlen($password) > 1024) {
    cli_fail('Password must be 12 to 1024 characters.');
}
$db = cli_db(cli_config($app));
try {
    $id = (new AdminRepository($db, new Clock()))->create($email, $name, $password);
} catch (PDOException $error) {
    cli_fail(Db::isDuplicate($error) ? 'An admin with that email already exists.' : 'Could not create admin (SQLSTATE ' . $error->getCode() . ').');
}
echo "Created admin #$id.\n";
