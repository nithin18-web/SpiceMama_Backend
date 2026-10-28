<?php
declare(strict_types=1);
namespace SpiceMama\Http;

final readonly class Request
{
    public function __construct(public string $method, public string $path, public array $query = []) {}

    public static function fromGlobals(): self
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = explode('?', $uri, 2)[0];
        if (preg_match('/%(?![0-9a-fA-F]{2})/', $path)) {
            throw new ApiException(400, 'bad_request', 'Malformed URL encoding.');
        }
        // Reject duplicate keys and bracket syntax rather than silently letting
        // PHP collapse ambiguous query parameters into one value.
        $query = [];
        $raw = explode('?', $uri, 2)[1] ?? '';
        foreach ($raw === '' ? [] : explode('&', $raw) as $pair) {
            if ($pair === '') {
                continue;
            }
            if (preg_match('/%(?![0-9a-fA-F]{2})/', $pair)) {
                throw new ApiException(400, 'bad_request', 'Malformed query encoding.');
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $key = urldecode($key);
            if ($key === '' || strpbrk($key, '[]') !== false || array_key_exists($key, $query)) {
                throw new ApiException(400, 'bad_request', 'Ambiguous query parameters.');
            }
            $query[$key] = urldecode($value);
        }
        return new self(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), rawurldecode($path), $query);
    }
}
