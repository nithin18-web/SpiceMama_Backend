<?php
declare(strict_types=1);
namespace SpiceMama\Validation;
use SpiceMama\Http\ApiException;

final class CatalogueQuery
{
    public static function parse(array $query): array
    {
        if (array_diff(array_keys($query), ['category', 'q', 'page', 'per_page']) !== []) {
            throw new ApiException(422, 'validation_failed', 'Unknown query parameter.');
        }
        $result = ['page' => 1, 'per_page' => 12, 'q' => '', 'category' => null];
        foreach ($query as $key => $value) {
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
                throw new ApiException(400, 'bad_request', 'Query values must be UTF-8 strings.');
            }
            if ($key === 'page' || $key === 'per_page') {
                $maximum = $key === 'page' ? 1000000 : 50;
                if (!preg_match('/^[0-9]+$/D', $value) || strlen($value) > 10 || (int) $value < 1 || (int) $value > $maximum) {
                    throw new ApiException(422, 'validation_failed', 'Invalid pagination.', [$key => "Use an integer between 1 and $maximum."]);
                }
                $result[$key] = (int) $value;
            } elseif ($key === 'category') {
                self::slug($value, 160);
                $result[$key] = $value;
            } else {
                if (mb_strlen($value) > 100) {
                    throw new ApiException(422, 'validation_failed', 'Search is too long.', ['q' => 'Use at most 100 characters.']);
                }
                $result['q'] = trim($value);
            }
        }
        return $result;
    }

    public static function slug(string $value, int $maximum = 200): string
    {
        if (strlen($value) > $maximum || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $value)) {
            throw new ApiException(422, 'validation_failed', 'Invalid slug.');
        }
        return $value;
    }
}
