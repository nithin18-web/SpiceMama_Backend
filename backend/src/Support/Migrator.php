<?php
declare(strict_types=1);
namespace SpiceMama\Support;
use PDO;

/**
 * Applies database/migrations/*.sql in name order, recording version and
 * SHA-256 checksum. Refuses edited applied migrations, missing files,
 * out-of-order additions and non-empty databases without history.
 * MariaDB DDL commits implicitly: a failure mid-file needs manual repair.
 */
final class Migrator
{
    public function __construct(private readonly PDO $db, private readonly string $directory, private readonly Clock $clock = new Clock()) {}

    /** @return array<string,string> version => path still to apply */
    public function pending(): array
    {
        $files = [];
        foreach (glob($this->directory . '/*.sql') ?: [] as $path) {
            $files[basename($path, '.sql')] = $path;
        }
        ksort($files, SORT_STRING);
        $applied = $this->applied();
        foreach ($applied as $version => $checksum) {
            if (!isset($files[$version])) {
                throw new \RuntimeException("Applied migration $version is missing from the repository.");
            }
            if (!hash_equals($checksum, hash_file('sha256', $files[$version]))) {
                throw new \RuntimeException("Applied migration $version has been modified; add a new migration instead.");
            }
        }
        $pending = array_diff_key($files, $applied);
        $last = array_key_last($applied);
        foreach (array_keys($pending) as $version) {
            if ($last !== null && strcmp($version, $last) < 0) {
                throw new \RuntimeException("Migration $version sorts before already-applied $last.");
            }
        }
        return $pending;
    }

    /** @return list<string> applied versions */
    public function apply(?\Closure $log = null): array
    {
        $done = [];
        foreach ($this->pending() as $version => $path) {
            $statements = self::statements((string) file_get_contents($path));
            foreach ($statements as $index => $sql) {
                try {
                    $this->db->exec($sql);
                } catch (\PDOException $error) {
                    throw new \RuntimeException("Migration $version failed at statement " . ($index + 1) . ' of ' . count($statements) . ' (SQLSTATE ' . $error->getCode() . '). Earlier statements are committed; inspect the database before retrying.', 0, $error);
                }
            }
            $this->db->prepare('INSERT INTO schema_migrations(version,checksum,applied_at) VALUES(?,?,?)')
                ->execute([$version, hash_file('sha256', $path), $this->clock->db()]);
            $done[] = $version;
            if ($log !== null) {
                $log($version);
            }
        }
        return $done;
    }

    /** Splits on statement-terminating semicolons; migrations contain no procedures or literal semicolons at line ends. */
    public static function statements(string $sql): array
    {
        $lines = array_filter(explode("\n", $sql), fn(string $line): bool => !str_starts_with(ltrim($line), '--'));
        $parts = preg_split('/;\s*(?:\n|$)/', implode("\n", $lines));
        return array_values(array_filter(array_map('trim', $parts), fn(string $part): bool => $part !== ''));
    }

    private function applied(): array
    {
        $exists = $this->db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='schema_migrations'")->fetchColumn();
        if ((int) $exists === 0) {
            $tables = (int) $this->db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn();
            if ($tables > 0) {
                throw new \RuntimeException('Database has tables but no migration history; refusing to guess its state.');
            }
            return [];
        }
        $rows = $this->db->query('SELECT version,checksum FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_KEY_PAIR);
        return $rows;
    }
}
