<?php
declare(strict_types=1);
namespace SpiceMama\Repositories;
use PDO;
use SpiceMama\Http\ApiException;
use SpiceMama\Services\{AuditLogger, PublicationRules};
use SpiceMama\Support\{Clock, Db};

final class ProductRepository
{
    public function __construct(private readonly PDO $db, private readonly Clock $clock) {}

    /** All statuses and inactive categories. */
    public function page(array $query): array
    {
        return ProductRows::page($this->db, ' FROM products p JOIN categories c ON c.id=p.category_id WHERE 1=1', $query);
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT ' . ProductRows::COLUMNS . ' FROM products p WHERE p.id=?');
        $statement->execute([$id]);
        return ProductRows::withImages($this->db, $statement->fetchAll())[0] ?? null;
    }

    /**
     * Row lock serialising every change to one product and its gallery.
     * Lock order everywhere: product row, then its images.
     */
    public function lock(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT id,category_id,status,published_at FROM products WHERE id=? FOR UPDATE');
        $statement->execute([$id]);
        return $statement->fetch() ?: null;
    }

    public function create(array $input, ?int $adminId): array
    {
        return CategoryRepository::uniqueSlug(fn(): array => Db::transaction($this->db, function () use ($input, $adminId): array {
            $this->requireCategory($input['category_id']);
            $now = $this->clock->db();
            $this->db->prepare("INSERT INTO products(category_id,name,slug,short_description,description,pack_size_label,ingredients,status,sort_order,created_at,updated_at) VALUES(?,?,?,?,?,?,?,'draft',?,?,?)")
                ->execute([$input['category_id'], $input['name'], $input['slug'], $input['short_description'], $input['description'], $input['pack_size_label'], $input['ingredients'], $input['sort_order'], $now, $now]);
            $id = ProductRows::safeId($this->db->lastInsertId());
            AuditLogger::record($this->db, $adminId, 'product.created', 'product', $id, null, $now);
            return $this->find($id);
        }));
    }

    /** Published products must still meet publication rules after the edit. */
    public function update(int $id, array $changes, ?int $adminId): ?array
    {
        return CategoryRepository::uniqueSlug(fn(): ?array => Db::transaction($this->db, function () use ($id, $changes, $adminId): ?array {
            $product = $this->lock($id);
            if ($product === null) {
                return null;
            }
            if (array_key_exists('category_id', $changes)) {
                $this->requireCategory($changes['category_id']);
            }
            $now = $this->clock->db();
            // Column names come from the validated allowlist in AdminInput.
            $set = implode(',', array_map(fn(string $column): string => "$column=?", array_keys($changes)));
            $this->db->prepare("UPDATE products SET $set,updated_at=? WHERE id=?")->execute([...array_values($changes), $now, $id]);
            if ($product['status'] === 'published') {
                PublicationRules::assert($this->db, $id);
            }
            AuditLogger::record($this->db, $adminId, 'product.updated', 'product', $id, ['fields' => array_keys($changes)], $now);
            return $this->find($id);
        }));
    }

    /** Any status to any other; first publication time is kept forever. Repeating the current status is a no-op. */
    public function setStatus(int $id, string $status, ?int $adminId): ?array
    {
        return Db::transaction($this->db, function () use ($id, $status, $adminId): ?array {
            $product = $this->lock($id);
            if ($product === null) {
                return null;
            }
            if ($product['status'] !== $status) {
                if ($status === 'published') {
                    PublicationRules::assert($this->db, $id);
                }
                $now = $this->clock->db();
                $this->db->prepare('UPDATE products SET status=?,published_at=COALESCE(published_at,?),updated_at=? WHERE id=?')
                    ->execute([$status, $status === 'published' ? $now : null, $now, $id]);
                AuditLogger::record($this->db, $adminId, 'product.status', 'product', $id, ['from' => $product['status'], 'to' => $status], $now);
            }
            return $this->find($id);
        });
    }

    private function requireCategory(int $categoryId): void
    {
        // Shared lock: the category cannot change underneath this write.
        $statement = $this->db->prepare('SELECT id FROM categories WHERE id=? LOCK IN SHARE MODE');
        $statement->execute([$categoryId]);
        if ($statement->fetch() === false) {
            throw new ApiException(422, 'validation_failed', 'Some fields are invalid.', ['category_id' => 'Category not found.']);
        }
    }
}
