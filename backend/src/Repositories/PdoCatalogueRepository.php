<?php
declare(strict_types=1);
namespace SpiceMama\Repositories;
use PDO;

final class PdoCatalogueRepository implements CatalogueReader
{
    private const COLUMNS = 'p.id,p.slug,p.name,p.category_id,p.short_description,p.description,p.pack_size_label,p.ingredients,p.status,p.sort_order';
    private const VISIBLE = " FROM products p JOIN categories c ON c.id=p.category_id WHERE p.status='published' AND c.is_active=1";

    public function __construct(private readonly PDO $db) {}

    public function products(array $query): array
    {
        $where = self::VISIBLE;
        $params = [];
        if ($query['category'] !== null) {
            $where .= ' AND c.slug=:category';
            $params['category'] = $query['category'];
        }
        if ($query['q'] !== '') {
            // Literal substring: user-supplied %, _ and escape characters are not wildcards.
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query['q']);
            $where .= " AND (p.name LIKE :name ESCAPE '!' OR p.short_description LIKE :short ESCAPE '!')";
            $params['name'] = $params['short'] = '%' . $escaped . '%';
        }
        // One repeatable-read snapshot keeps totals, products and galleries consistent.
        $this->db->beginTransaction();
        try {
            $count = $this->db->prepare('SELECT COUNT(*)' . $where);
            $count->execute($params);
            $total = (int) $count->fetchColumn();
            $offset = ($query['page'] - 1) * $query['per_page'];
            $statement = $this->db->prepare('SELECT ' . self::COLUMNS . $where . ' ORDER BY p.sort_order,p.id LIMIT :limit OFFSET :offset');
            foreach ($params as $key => $value) {
                $statement->bindValue(':' . $key, $value, PDO::PARAM_STR);
            }
            $statement->bindValue(':limit', $query['per_page'], PDO::PARAM_INT);
            $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
            $statement->execute();
            $rows = $this->withImages($statement->fetchAll());
            $this->db->commit();
            return ['data' => $rows, 'meta' => ['page' => $query['page'], 'per_page' => $query['per_page'], 'total' => $total, 'total_pages' => (int) ceil($total / $query['per_page'])]];
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function product(string $slug): ?array
    {
        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare('SELECT ' . self::COLUMNS . self::VISIBLE . ' AND p.slug=:slug');
            $statement->execute(['slug' => $slug]);
            $rows = $this->withImages($statement->fetchAll());
            $this->db->commit();
            return $rows[0] ?? null;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    private function withImages(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_column($rows, 'id');
        $statement = $this->db->prepare('SELECT id,product_id,file_path,alt_text,sort_order FROM product_images WHERE product_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY sort_order,id');
        $statement->execute($ids);
        $images = [];
        foreach ($statement->fetchAll() as $image) {
            // Fail closed if imported database metadata contains unsafe paths.
            if (!preg_match('/^[a-f0-9]{32}\.(?:jpg|png|webp)$/D', $image['file_path'])) {
                throw new \RuntimeException('Unsafe image metadata.');
            }
            $images[$image['product_id']][] = ['id' => self::safeId($image['id']), 'url' => '/uploads/products/' . $image['file_path'], 'alt_text' => $image['alt_text'], 'sort_order' => (int) $image['sort_order']];
        }
        foreach ($rows as &$row) {
            foreach (['id', 'category_id', 'sort_order'] as $key) {
                $row[$key] = $key === 'sort_order' ? (int) $row[$key] : self::safeId($row[$key]);
            }
            $row['images'] = $images[$row['id']] ?? [];
        }
        return $rows;
    }

    private static function safeId(int|string $value): int
    {
        if (!preg_match('/^[1-9][0-9]*$/D', (string) $value) || strlen((string) $value) > 16 || (int) $value > 9007199254740991) {
            throw new \RuntimeException('Unsafe record identifier.');
        }
        return (int) $value;
    }

    public function categories(): array
    {
        $rows = $this->db->query('SELECT id,name,slug,description,sort_order,is_active FROM categories WHERE is_active=1 ORDER BY sort_order,id')->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = self::safeId($row['id']);
            $row['sort_order'] = (int) $row['sort_order'];
            $row['is_active'] = (bool) $row['is_active'];
        }
        return $rows;
    }
}
