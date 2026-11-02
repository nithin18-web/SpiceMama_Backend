<?php
declare(strict_types=1);
namespace SpiceMama\Repositories;
use PDO;

final class PdoCatalogueRepository implements CatalogueReader
{
    private const VISIBLE = " FROM products p JOIN categories c ON c.id=p.category_id WHERE p.status='published' AND c.is_active=1";

    public function __construct(private readonly PDO $db) {}

    public function products(array $query): array
    {
        return ProductRows::page($this->db, self::VISIBLE, $query);
    }

    public function product(string $slug): ?array
    {
        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare('SELECT ' . ProductRows::COLUMNS . self::VISIBLE . ' AND p.slug=:slug');
            $statement->execute(['slug' => $slug]);
            $rows = ProductRows::withImages($this->db, $statement->fetchAll());
            $this->db->commit();
            return $rows[0] ?? null;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function categories(): array
    {
        $rows = $this->db->query('SELECT id,name,slug,description,sort_order,is_active FROM categories WHERE is_active=1 ORDER BY sort_order,id')->fetchAll();
        return array_map(ProductRows::category(...), $rows);
    }
}
