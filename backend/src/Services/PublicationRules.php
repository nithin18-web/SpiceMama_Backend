<?php
declare(strict_types=1);
namespace SpiceMama\Services;
use PDO;
use SpiceMama\Http\ApiException;
use SpiceMama\Validation\AdminInput;

/** Checks a product's current (in-transaction) state; caller must hold the product row lock. */
final class PublicationRules
{
    public static function problems(PDO $db, int $productId): array
    {
        $statement = $db->prepare('SELECT p.short_description,p.description,c.is_active FROM products p JOIN categories c ON c.id=p.category_id WHERE p.id=?');
        $statement->execute([$productId]);
        $product = $statement->fetch();
        $images = $db->prepare('SELECT alt_text FROM product_images WHERE product_id=?');
        $images->execute([$productId]);
        $problems = [];
        if (!$product['is_active']) {
            $problems['category_id'] = 'Category must be active.';
        }
        foreach (['short_description', 'description'] as $field) {
            if (AdminInput::blank($product[$field])) {
                $problems[$field] = 'Required for publishing.';
            }
        }
        if (array_filter($images->fetchAll(PDO::FETCH_COLUMN), fn(string $alt): bool => !AdminInput::blank($alt)) === []) {
            $problems['images'] = 'Add at least one image with alt text.';
        }
        return $problems;
    }

    public static function assert(PDO $db, int $productId): void
    {
        if (($problems = self::problems($db, $productId)) !== []) {
            throw new ApiException(422, 'validation_failed', 'Product does not meet publishing requirements.', $problems);
        }
    }
}
