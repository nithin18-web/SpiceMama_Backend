<?php
declare(strict_types=1);
namespace SpiceMama\Http;

final readonly class Request
{
    /** JSON bodies larger than this are rejected before parsing (10,000-character fields fit comfortably). */
    public const MAX_JSON_BYTES = 262144;

    /**
     * @param array<string,string> $headers lower-case names
     * @param array<string,mixed> $form multipart text fields
     * @param array<string,array> $files multipart files; each carries 'uploaded' => is_uploaded_file()
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $query = [],
        public array $headers = [],
        public string $body = '',
        public array $form = [],
        public array $files = [],
        public string $clientIp = '',
    ) {}

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
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($value) && str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $key => $name) {
            if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
                $headers[$name] = $_SERVER[$key];
            }
        }
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $body = '';
        $form = $files = [];
        if (self::mediaType($headers['content-type'] ?? '') === 'multipart/form-data') {
            // PHP has already parsed multipart input; uploaded flags prove files came from this request.
            $form = $_POST;
            foreach ($_FILES as $name => $file) {
                $file['uploaded'] = is_string($file['tmp_name'] ?? null) && $file['tmp_name'] !== '' && is_uploaded_file($file['tmp_name']);
                $files[$name] = $file;
            }
        } elseif (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $body = (string) file_get_contents('php://input', false, null, 0, self::MAX_JSON_BYTES + 1);
            if (strlen($body) > self::MAX_JSON_BYTES) {
                throw new ApiException(413, 'payload_too_large', 'Request body is too large.');
            }
        }
        return new self($method, rawurldecode($path), $query, $headers, $body, $form, $files, (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** Decodes a JSON object body: wrong media type 415, malformed or non-object 400. */
    public function json(): array
    {
        if (self::mediaType($this->header('content-type') ?? '') !== 'application/json') {
            throw new ApiException(415, 'unsupported_media_type', 'Send a JSON request body.');
        }
        if (!preg_match('/^\s*\{/', $this->body)) {
            throw new ApiException(400, 'bad_request', 'Request body must be a JSON object.');
        }
        try {
            // Oversized integers become strings and then fail integer validation.
            return json_decode($this->body, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new ApiException(400, 'bad_request', 'Malformed JSON.');
        }
    }

    public static function mediaType(string $contentType): string
    {
        return strtolower(trim(explode(';', $contentType, 2)[0]));
    }
}
