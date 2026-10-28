<?php
declare(strict_types=1);
namespace SpiceMama\Http;

final readonly class Response
{
    public function __construct(public int $status, public ?array $body = null) {}

    public static function error(ApiException $error): self
    {
        $payload = ['code' => $error->errorCode, 'message' => $error->getMessage()];
        if ($error->fields !== []) {
            $payload['fields'] = (object) $error->fields;
        }
        return new self($error->status, ['error' => $payload]);
    }

    public function send(): void
    {
        // Encode before emitting a successful status so encoding failure cannot
        // leave a misleading success response on the wire.
        $json = $this->body === null ? '' : json_encode($this->body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('Cache-Control: no-store');
        echo $json;
    }
}
