<?php
declare(strict_types=1);
namespace SpiceMama\Validation;
use SpiceMama\Http\ApiException;
use SpiceMama\Repositories\ProductRows;

/** Strict request-body validation matching API_CONTRACT.yaml schemas. */
final class AdminInput
{
    public const MAX_UPLOAD_BYTES = 5242880;
    private const SORT = ['int', 0, 2147483647];
    private const PRODUCT = [
        'name' => ['text', 1, 180], 'slug' => ['slug', 200], 'category_id' => ['id'],
        'short_description' => ['text', 0, 500], 'description' => ['text', 0, 10000],
        'pack_size_label' => ['nullable', 0, 100], 'ingredients' => ['nullable', 0, 10000], 'sort_order' => self::SORT,
    ];
    private const CATEGORY = [
        'name' => ['text', 1, 120], 'slug' => ['slug', 160], 'description' => ['nullable', 0, 10000],
        'sort_order' => self::SORT, 'is_active' => ['bool'],
    ];

    public static function login(array $body): array
    {
        $input = self::object($body, ['email' => ['email'], 'password' => ['text', 1, 1024]], ['email', 'password']);
        $input['email'] = self::email($input['email']);
        return $input;
    }

    /** Emails are compared trimmed and ASCII-lowercased, both at provisioning and login. */
    public static function email(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function productCreate(array $body): array
    {
        return self::object($body, self::PRODUCT, ['name', 'slug', 'category_id']) +
            ['short_description' => '', 'description' => '', 'pack_size_label' => null, 'ingredients' => null, 'sort_order' => 0];
    }

    public static function productPatch(array $body): array
    {
        return self::object($body, self::PRODUCT, [], true);
    }

    public static function categoryCreate(array $body): array
    {
        return self::object($body, self::CATEGORY, ['name', 'slug']) + ['description' => null, 'sort_order' => 0, 'is_active' => false];
    }

    public static function categoryPatch(array $body): array
    {
        return self::object($body, self::CATEGORY, [], true);
    }

    public static function status(array $body): string
    {
        return self::object($body, ['status' => ['enum', CatalogueQuery::STATUSES]], ['status'])['status'];
    }

    public static function altText(array $body): string
    {
        return self::object($body, ['alt_text' => ['text', 0, 255]], ['alt_text'])['alt_text'];
    }

    /** @return list<int> */
    public static function imageOrder(array $body): array
    {
        return self::object($body, ['image_ids' => ['ids']], ['image_ids'])['image_ids'];
    }

    /** Multipart upload fields; returns [product_id, alt_text, file]. */
    public static function upload(array $form, array $files): array
    {
        foreach ($form as $value) {
            if (!is_string($value)) {
                throw new ApiException(400, 'bad_request', 'Repeated or nested form fields are not allowed.');
            }
        }
        $unknown = array_merge(array_diff(array_keys($form), ['product_id', 'alt_text']), array_diff(array_keys($files), ['file']));
        if ($unknown !== []) {
            throw new ApiException(422, 'validation_failed', 'Unknown request property.', array_fill_keys($unknown, 'Unknown property.'));
        }
        $errors = [];
        $productId = null;
        if (!isset($form['product_id'])) {
            $errors['product_id'] = 'Required.';
        } elseif (($productId = self::parseId($form['product_id'])) === null) {
            $errors['product_id'] = 'Use a positive integer identifier.';
        }
        $alt = $form['alt_text'] ?? '';
        if (($error = self::check($alt, ['text', 0, 255])) !== null) {
            $errors['alt_text'] = $error;
        }
        $file = $files['file'] ?? null;
        if ($file !== null && (!is_int($file['error'] ?? null) || !is_int($file['size'] ?? null))) {
            throw new ApiException(400, 'bad_request', 'Upload exactly one file.');
        }
        if ($file !== null && in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new ApiException(413, 'payload_too_large', 'Image is too large.', ['file' => 'Use a file of at most 5 MB.']);
        }
        if ($file === null || $file['error'] === UPLOAD_ERR_NO_FILE) {
            $errors['file'] = 'Required.';
        } elseif ($file['error'] !== UPLOAD_ERR_OK || ($file['uploaded'] ?? false) !== true || $file['size'] < 1) {
            $errors['file'] = 'The file could not be received. Try again.';
        } elseif ($file['size'] > self::MAX_UPLOAD_BYTES) {
            throw new ApiException(413, 'payload_too_large', 'Image is too large.', ['file' => 'Use a file of at most 5 MB.']);
        }
        if ($errors !== []) {
            throw new ApiException(422, 'validation_failed', 'Some fields are invalid.', $errors);
        }
        return [$productId, $alt, $file];
    }

    /** Path identifier: 1..2^53-1 or 422. */
    public static function id(string $raw): int
    {
        return self::parseId($raw) ?? throw new ApiException(422, 'validation_failed', 'Invalid identifier.', ['id' => 'Use a positive integer identifier.']);
    }

    public static function noQuery(array $query): void
    {
        if ($query !== []) {
            throw new ApiException(422, 'validation_failed', 'Unknown query parameter.');
        }
    }

    /** Whitespace-only text (including Unicode separators) counts as blank. */
    public static function blank(?string $value): bool
    {
        return $value === null || !preg_match('/[^\p{Z}\s]/u', $value);
    }

    private static function parseId(string $raw): ?int
    {
        return preg_match('/^[1-9][0-9]{0,15}$/D', $raw) && (int) $raw <= ProductRows::MAX_ID ? (int) $raw : null;
    }

    private static function object(array $body, array $rules, array $required, bool $patch = false): array
    {
        $unknown = array_diff(array_map('strval', array_keys($body)), array_keys($rules));
        if ($unknown !== []) {
            throw new ApiException(422, 'validation_failed', 'Unknown request property.', array_fill_keys($unknown, 'Unknown property.'));
        }
        if ($patch && $body === []) {
            throw new ApiException(422, 'validation_failed', 'Provide at least one field to change.');
        }
        $errors = [];
        foreach ($required as $key) {
            if (!array_key_exists($key, $body)) {
                $errors[$key] = 'Required.';
            }
        }
        foreach ($body as $key => $value) {
            if (($error = self::check($value, $rules[$key])) !== null) {
                $errors[$key] = $error;
            }
        }
        if ($errors !== []) {
            throw new ApiException(422, 'validation_failed', 'Some fields are invalid.', $errors);
        }
        return $body;
    }

    private static function check(mixed $value, array $rule): ?string
    {
        switch ($rule[0]) {
            case 'nullable':
                if ($value === null) {
                    return null;
                }
                // no break
            case 'text':
                if (!is_string($value)) {
                    return 'Must be text.';
                }
                if ($rule[1] > 0 && self::blank($value)) {
                    return 'Required.';
                }
                return mb_strlen($value) > $rule[2] ? "Use at most {$rule[2]} characters." : null;
            case 'email':
                return is_string($value) && strlen($value) <= 254 && filter_var(trim($value), FILTER_VALIDATE_EMAIL) !== false ? null : 'Enter a valid email address.';
            case 'slug':
                return is_string($value) && strlen($value) <= $rule[1] && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $value)
                    ? null : 'Use lowercase letters and numbers separated by single hyphens.';
            case 'id':
                return is_int($value) && $value >= 1 && $value <= ProductRows::MAX_ID ? null : 'Use a positive integer identifier.';
            case 'int':
                return is_int($value) && $value >= $rule[1] && $value <= $rule[2] ? null : "Use an integer between {$rule[1]} and {$rule[2]}.";
            case 'bool':
                return is_bool($value) ? null : 'Must be true or false.';
            case 'enum':
                return in_array($value, $rule[1], true) ? null : 'Use one of: ' . implode(', ', $rule[1]) . '.';
            case 'ids':
                if (!is_array($value) || !array_is_list($value) || count($value) > 1000) {
                    return 'Use a list of image identifiers.';
                }
                foreach ($value as $id) {
                    if (!is_int($id) || $id < 1 || $id > ProductRows::MAX_ID) {
                        return 'Use a list of image identifiers.';
                    }
                }
                return count(array_unique($value)) === count($value) ? null : 'Each image may appear only once.';
        }
        throw new \LogicException('Unknown validation rule.');
    }
}
