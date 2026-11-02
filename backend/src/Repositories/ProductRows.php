<?php
declare(strict_types=1);
namespace SpiceMama\Repositories;
use PDO;

/** Shared product/gallery hydration so public and admin responses keep one JSON shape. */
final class ProductRows
{
    public const COLUMNS = 'p.id,p.slug,p.name,p.category_id,p.short_description,p.description,p.pack_size_label,p.ingredients,p.status,p.sort_order';
    public const MAX_ID = 9007199254740991;

    /** Paginated list; $from must end in a WHERE clause. Filters are always bound. */
    public static function page(PDO $db, string $from, array $query): array
    {
        $where = $from;
        $params = [];
        if ($query['category'] !== null) {
            $where .= ' AND c.slug=:category';
            $params['category'] = $query['category'];
        }
        if (($query['status'] ?? null) !== null) {
            $where .= ' AND p.status=:status';
            $params['status'] = $query['status'];
        }
        if ($query['q'] !== '') {
            // Literal substring: user-supplied %, _ and escape characters are not wildcards.
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query['q']);
            $where .= " AND (p.name LIKE :name ESCAPE '!' OR p.short_description LIKE :short ESCAPE '!')";
            $params['name'] = $params['short'] = '%' . $escaped . '%';
        }
        // One repeatable-read snapshot keeps totals, products and galleries consistent.
        $db->beginTransaction();
        try {
            $count = $db->prepare('SELECT COUNT(*)' . $where);
            $count->execute($params);
            $total = (int) $count->fetchColumn();
            $offset = ($query['page'] - 1) * $query['per_page'];
            $statement = $db->prepare('SELECT ' . self::COLUMNS . $where . ' ORDER BY p.sort_order,p.id LIMIT :limit OFFSET :offset');
            foreach ($params as $key => $value) {
                $statement->bindValue(':' . $key, $value, PDO::PARAM_STR);
            }
            $statement->bindValue(':limit', $query['per_page'], PDO::PARAM_INT);
            $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
            $statement->execute();
            $rows = self::withImages($db, $statement->fetchAll());
            $db->commit();
            return ['data' => $rows, 'meta' => ['page' => $query['page'], 'per_page' => $query['per_page'], 'total' => $total, 'total_pages' => (int) ceil($total / $query['per_page'])]];
        } catch (\Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }

    public static function withImages(PDO $db, array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_column($rows, 'id');
        $statement = $db->prepare('SELECT id,product_id,file_path,alt_text,sort_order FROM product_images WHERE product_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY sort_order,id');
        $statement->execute($ids);
        $images = [];
        foreach ($statement->fetchAll() as $image) {
            $images[$image['product_id']][] = self::image($image);
        }
        foreach ($rows as &$row) {
            foreach (['id', 'category_id', 'sort_order'] as $key) {
                $row[$key] = $key === 'sort_order' ? (int) $row[$key] : self::safeId($row[$key]);
            }
            $row['images'] = $images[$row['id']] ?? [];
        }
        return $rows;
    }

    public static function image(array $image): array
    {
        // Fail closed if imported database metadata contains unsafe paths.
        if (!preg_match('/^[a-f0-9]{32}\.(?:jpg|png|webp)$/D', $image['file_path'])) {
            throw new \RuntimeException('Unsafe image metadata.');
        }
        return ['id' => self::safeId($image['id']), 'url' => '/uploads/products/' . $image['file_path'], 'alt_text' => $image['alt_text'], 'sort_order' => (int) $image['sort_order']];
    }

    public static function category(array $row): array
    {
        return ['id' => self::safeId($row['id']), 'name' => $row['name'], 'slug' => $row['slug'], 'description' => $row['description'], 'sort_order' => (int) $row['sort_order'], 'is_active' => (bool) $row['is_active']];
    }

    public static function safeId(int|string $value): int
    {
        if (!preg_match('/^[1-9][0-9]*$/D', (string) $value) || strlen((string) $value) > 16 || (int) $value > self::MAX_ID) {
            throw new \RuntimeException('Unsafe record identifier.');
        }
        return (int) $value;
    }
}
