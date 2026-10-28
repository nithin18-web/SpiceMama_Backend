<?php
declare(strict_types=1);
namespace SpiceMama\Services;
use SpiceMama\Http\ApiException;

/** Session primitives only. No login route is exposed in the first milestone. */
final class SessionSecurity
{
    public static function start(string $privateDirectory, bool $secure = true): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            throw new \LogicException('Session already started.');
        }
        if (!is_dir($privateDirectory) || !is_writable($privateDirectory)) {
            throw new \RuntimeException('Private session directory unavailable.');
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', '28800');
        session_name('PHPSESSID');
        session_save_path($privateDirectory);
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
        if (!session_start()) {
            throw new \RuntimeException('Cannot start session.');
        }
    }

    public static function token(array &$session): string
    {
        if (!isset($session['csrf']) || !is_string($session['csrf'])) {
            $session['csrf'] = bin2hex(random_bytes(32));
        }
        return $session['csrf'];
    }

    public static function verifyCsrf(array $session, ?string $provided): void
    {
        if ($provided === null || !isset($session['csrf']) || !is_string($session['csrf']) || !hash_equals($session['csrf'], $provided)) {
            throw new ApiException(403, 'csrf_invalid', 'Refresh your session and try again.');
        }
    }

    public static function authenticated(array &$session, int $now): int
    {
        if (!isset($session['admin_id'], $session['authenticated_at'], $session['last_seen']) ||
            !is_int($session['admin_id']) || $session['admin_id'] < 1 ||
            !is_int($session['authenticated_at']) || !is_int($session['last_seen']) ||
            $now < $session['authenticated_at'] || $now < $session['last_seen'] ||
            $now - $session['authenticated_at'] >= 28800 || $now - $session['last_seen'] >= 1800) {
            $session = [];
            throw new ApiException(401, 'unauthorized', 'Please sign in.');
        }
        $session['last_seen'] = $now;
        return $session['admin_id'];
    }
}
