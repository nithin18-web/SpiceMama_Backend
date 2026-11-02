<?php
declare(strict_types=1);
namespace SpiceMama\Services;
use SpiceMama\Http\ApiException;

final class NativeSession implements SessionStore
{
    public function __construct(private readonly string $directory, private readonly bool $secure) {}

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (!str_starts_with($this->directory, '/')) {
            throw new ApiException(503, 'unavailable', 'Admin service is not configured.');
        }
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0700, true);
        }
        SessionSecurity::start($this->directory, $this->secure);
    }

    public function &data(): array
    {
        return $_SESSION;
    }

    public function regenerate(): void
    {
        if (!session_regenerate_id(true)) {
            throw new \RuntimeException('Cannot regenerate session.');
        }
    }

    public function destroy(): void
    {
        $_SESSION = [];
        $params = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => 1, 'path' => $params['path'], 'secure' => $params['secure'], 'httponly' => true, 'samesite' => 'Lax']);
        session_destroy();
    }
}
