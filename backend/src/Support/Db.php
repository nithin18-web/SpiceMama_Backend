<?php
declare(strict_types=1);
namespace SpiceMama\Support;
use PDO;

final class Db
{
    public static function transaction(PDO $db, \Closure $work): mixed
    {
        $db->beginTransaction();
        try {
            $result = $work();
            $db->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }

    public static function isDuplicate(\PDOException $error): bool
    {
        return ($error->errorInfo[1] ?? null) === 1062;
    }

    /** Lock wait timeout or deadlock: the transaction was rolled back and may be retried by the user. */
    public static function isContention(\Throwable $error): bool
    {
        return $error instanceof \PDOException && in_array($error->errorInfo[1] ?? null, [1205, 1213], true);
    }
}
