<?php
declare(strict_types=1);
namespace SpiceMama\Repositories;
use PDO;
use SpiceMama\Services\AuditLogger;
use SpiceMama\Support\{Clock, Db};

final class AdminRepository
{
    public function __construct(private readonly PDO $db, private readonly Clock $clock) {}

    public function find(int $id): ?array
    {
        return $this->one('id', $id);
    }

    public function findByEmail(string $email): ?array
    {
        return $this->one('email', $email);
    }

    /** Records the sign-in and upgrades the stored hash when PHP's default algorithm changes. */
    public function recordLogin(int $id, string $password, string $hash): void
    {
        Db::transaction($this->db, function () use ($id, $password, $hash): void {
            $now = $this->clock->db();
            $this->db->prepare('UPDATE admins SET last_login_at=?,updated_at=? WHERE id=?')->execute([$now, $now, $id]);
            if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                $this->db->prepare('UPDATE admins SET password_hash=? WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
            }
            AuditLogger::record($this->db, $id, 'admin.login', 'admin', $id, null, $now);
        });
    }

    public function recordLogout(int $id): void
    {
        AuditLogger::record($this->db, $id, 'admin.logout', 'admin', $id, null, $this->clock->db());
    }

    /** CLI provisioning only; there is no API for creating admins. */
    public function create(string $email, string $displayName, string $password): int
    {
        return Db::transaction($this->db, function () use ($email, $displayName, $password): int {
            $now = $this->clock->db();
            $this->db->prepare("INSERT INTO admins(email,password_hash,display_name,role,is_active,created_at,updated_at) VALUES(?,?,?,'admin',1,?,?)")
                ->execute([$email, password_hash($password, PASSWORD_DEFAULT), $displayName, $now, $now]);
            $id = ProductRows::safeId($this->db->lastInsertId());
            AuditLogger::record($this->db, null, 'admin.provisioned', 'admin', $id, null, $now);
            return $id;
        });
    }

    public static function public(array $admin): array
    {
        return ['id' => $admin['id'], 'email' => $admin['email'], 'display_name' => $admin['display_name']];
    }

    private function one(string $column, int|string $value): ?array
    {
        $statement = $this->db->prepare("SELECT id,email,password_hash,display_name,is_active FROM admins WHERE $column=?");
        $statement->execute([$value]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }
        $row['id'] = ProductRows::safeId($row['id']);
        $row['is_active'] = (bool) $row['is_active'];
        return $row;
    }
}
