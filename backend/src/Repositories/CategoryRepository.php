<?php
declare(strict_types=1);
namespace SpiceMama\Repositories;
use PDO;
use SpiceMama\Http\ApiException;
use SpiceMama\Services\AuditLogger;
use SpiceMama\Support\{Clock, Db};

final class CategoryRepository
{
    private const COLUMNS = 'id,name,slug,description,sort_order,is_active';

    public function __construct(private readonly PDO $db, private readonly Clock $clock) {}

    public function all(): array
    {
        return array_map(ProductRows::category(...), $this->db->query('SELECT ' . self::COLUMNS . ' FROM categories ORDER BY sort_order,id')->fetchAll());
    }

    public function find(int $id, bool $lock = false): ?array
    {
        $statement = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM categories WHERE id=?' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([$id]);
        $row = $statement->fetch();
        return $row === false ? null : ProductRows::category($row);
    }

    public function create(array $input, ?int $adminId): array
    {
        return self::uniqueSlug(fn(): array => Db::transaction($this->db, function () use ($input, $adminId): array {
            $now = $this->clock->db();
            $this->db->prepare('INSERT INTO categories(name,slug,description,sort_order,is_active,created_at,updated_at) VALUES(?,?,?,?,?,?,?)')
                ->execute([$input['name'], $input['slug'], $input['description'], $input['sort_order'], (int) $input['is_active'], $now, $now]);
            $id = ProductRows::safeId($this->db->lastInsertId());
            AuditLogger::record($this->db, $adminId, 'category.created', 'category', $id, null, $now);
            return $this->find($id);
        }));
    }

    /** Deactivation hides products publicly without touching their status. */
    public function update(int $id, array $changes, ?int $adminId): ?array
    {
        return self::uniqueSlug(fn(): ?array => Db::transaction($this->db, function () use ($id, $changes, $adminId): ?array {
            if ($this->find($id, true) === null) {
                return null;
            }
            $now = $this->clock->db();
            if (array_key_exists('is_active', $changes)) {
                $changes['is_active'] = (int) $changes['is_active'];
            }
            // Column names come from the validated allowlist in AdminInput.
            $set = implode(',', array_map(fn(string $column): string => "$column=?", array_keys($changes)));
            $this->db->prepare("UPDATE categories SET $set,updated_at=? WHERE id=?")->execute([...array_values($changes), $now, $id]);
            AuditLogger::record($this->db, $adminId, 'category.updated', 'category', $id, ['fields' => array_keys($changes)], $now);
            return $this->find($id);
        }));
    }

    /** Duplicate slugs surface as 409 whichever table raised them. */
    public static function uniqueSlug(\Closure $write): mixed
    {
        try {
            return $write();
        } catch (\PDOException $error) {
            if (Db::isDuplicate($error)) {
                throw new ApiException(409, 'conflict', 'That slug is already in use.', ['slug' => 'Choose a different slug.']);
            }
            throw $error;
        }
    }
}
