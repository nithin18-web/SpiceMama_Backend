<?php
declare(strict_types=1);
namespace SpiceMama\Support;
use PDO;

final class Database
{
    public static function connect(array $config): PDO
    {
        foreach (['host', 'port', 'database', 'username', 'password'] as $key) {
            if (!array_key_exists($key, $config) || !is_scalar($config[$key])) {
                throw new \RuntimeException('Database configuration is incomplete.');
            }
        }
        if (!preg_match('/^[a-zA-Z0-9_.-]+$/D', (string) $config['host']) ||
            !preg_match('/^[a-zA-Z0-9_]+$/D', (string) $config['database']) ||
            filter_var($config['port'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
            throw new \RuntimeException('Invalid database connection settings.');
        }
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $config['database']),
            (string) $config['username'], (string) $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        );
        $pdo->exec("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ");
        $pdo->exec("SET time_zone = '+00:00'");
        return $pdo;
    }
}
