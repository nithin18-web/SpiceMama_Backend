<?php
declare(strict_types=1);
namespace SpiceMama\Repositories;
use PDO;
use SpiceMama\Http\ApiException;
use SpiceMama\Services\AuditLogger;
use SpiceMama\Support\{Clock, Db};
use SpiceMama\Validation\AdminInput;

/**
 * Gallery invariants: sort_order is 0..n-1, the first image is the only
 * is_primary row, and a published product keeps an image with alt text.
 */
final class ImageRepository
{
    private readonly ProductRepository $products;

    public function __construct(private readonly PDO $db, private readonly Clock $clock)
    {
        $this->products = new ProductRepository($db, $clock);
    }

    /** Appends to the gallery; null when the product does not exist. */
    public function add(int $productId, string $fileName, array $meta, string $altText, ?int $adminId): ?array
    {
        return Db::transaction($this->db, function () use ($productId, $fileName, $meta, $altText, $adminId): ?array {
            if ($this->products->lock($productId) === null) {
                return null;
            }
            $gallery = $this->gallery($productId);
            $now = $this->clock->db();
            $this->db->prepare('INSERT INTO product_images(product_id,file_path,alt_text,sort_order,is_primary,width,height,mime_type,file_size_bytes,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)')
                ->execute([$productId, $fileName, $altText, count($gallery), (int) ($gallery === []), $meta['width'], $meta['height'], $meta['mime'], $meta['bytes'], $now]);
            $id = ProductRows::safeId($this->db->lastInsertId());
            AuditLogger::record($this->db, $adminId, 'image.uploaded', 'product_image', $id, ['product_id' => $productId], $now);
            return $this->find($id);
        });
    }

    public function updateAlt(int $imageId, string $altText, ?int $adminId): ?array
    {
        return $this->withImage($imageId, function (array $product, array $gallery) use ($imageId, $altText, $adminId): array {
            if ($product['status'] === 'published' && AdminInput::blank($altText) && !$this->hasAlt($gallery, $imageId)) {
                throw new ApiException(422, 'validation_failed', 'A published product needs at least one image with alt text.', ['alt_text' => 'Required while this is the only described image.']);
            }
            $now = $this->clock->db();
            $this->db->prepare('UPDATE product_images SET alt_text=? WHERE id=?')->execute([$altText, $imageId]);
            AuditLogger::record($this->db, $adminId, 'image.updated', 'product_image', $imageId, ['fields' => ['alt_text']], $now);
            return $this->find($imageId);
        });
    }

    /** Returns the stored file name for deletion after commit; null when missing. */
    public function remove(int $imageId, ?int $adminId): ?string
    {
        return $this->withImage($imageId, function (array $product, array $gallery) use ($imageId, $adminId): string {
            if ($product['status'] === 'published' && !$this->hasAlt($gallery, $imageId)) {
                throw new ApiException(409, 'conflict', 'A published product must keep at least one image with alt text. Unpublish it first or add another image.');
            }
            $fileName = $gallery[$imageId]['file_path'];
            $this->db->prepare('DELETE FROM product_images WHERE id=?')->execute([$imageId]);
            unset($gallery[$imageId]);
            $this->renumber(array_keys($gallery));
            AuditLogger::record($this->db, $adminId, 'image.removed', 'product_image', $imageId, ['product_id' => (int) $product['id']], $this->clock->db());
            return $fileName;
        });
    }

    /** Replaces the complete order. Null when the product is missing; 409 for stale or foreign sets. */
    public function reorder(int $productId, array $imageIds, ?int $adminId): ?array
    {
        return Db::transaction($this->db, function () use ($productId, $imageIds, $adminId): ?array {
            if ($this->products->lock($productId) === null) {
                return null;
            }
            $current = array_keys($this->gallery($productId));
            $submitted = $imageIds;
            sort($current);
            sort($submitted);
            if ($current !== $submitted) {
                throw new ApiException(409, 'conflict', 'The gallery has changed. Reload the product and try again.');
            }
            $this->renumber($imageIds);
            AuditLogger::record($this->db, $adminId, 'image.reordered', 'product', $productId, ['image_ids' => $imageIds], $this->clock->db());
            return $this->products->find($productId);
        });
    }

    public function find(int $imageId): ?array
    {
        $statement = $this->db->prepare('SELECT id,file_path,alt_text,sort_order FROM product_images WHERE id=?');
        $statement->execute([$imageId]);
        $row = $statement->fetch();
        return $row === false ? null : ProductRows::image($row);
    }

    /** Locks product then gallery; null when the image does not exist (or vanished concurrently). */
    private function withImage(int $imageId, \Closure $work): mixed
    {
        $owner = $this->db->prepare('SELECT product_id FROM product_images WHERE id=?');
        $owner->execute([$imageId]);
        $productId = $owner->fetchColumn();
        if ($productId === false) {
            return null;
        }
        return Db::transaction($this->db, function () use ($imageId, $productId, $work): mixed {
            $product = $this->products->lock((int) $productId);
            $gallery = $this->gallery((int) $productId);
            return $product === null || !isset($gallery[$imageId]) ? null : $work($product, $gallery);
        });
    }

    /** @return array<int,array> keyed by image id in display order, rows locked */
    private function gallery(int $productId): array
    {
        $statement = $this->db->prepare('SELECT id,file_path,alt_text FROM product_images WHERE product_id=? ORDER BY sort_order,id FOR UPDATE');
        $statement->execute([$productId]);
        $gallery = [];
        foreach ($statement->fetchAll() as $row) {
            $gallery[(int) $row['id']] = $row;
        }
        return $gallery;
    }

    private function hasAlt(array $gallery, int $exceptImageId): bool
    {
        foreach ($gallery as $id => $image) {
            if ($id !== $exceptImageId && !AdminInput::blank($image['alt_text'])) {
                return true;
            }
        }
        return false;
    }

    private function renumber(array $orderedIds): void
    {
        $update = $this->db->prepare('UPDATE product_images SET sort_order=?,is_primary=? WHERE id=?');
        foreach (array_values($orderedIds) as $position => $id) {
            $update->execute([$position, (int) ($position === 0), $id]);
        }
    }
}
