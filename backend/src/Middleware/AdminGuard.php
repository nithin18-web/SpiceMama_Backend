<?php
declare(strict_types=1);
namespace SpiceMama\Middleware;
use SpiceMama\Http\{ApiException, Request};
use SpiceMama\Repositories\AdminRepository;
use SpiceMama\Services\{SessionSecurity, SessionStore};
use SpiceMama\Support\Clock;

/** Session authentication (401) then CSRF for non-GET requests (403), checked before any input parsing. */
final class AdminGuard
{
    public function __construct(private readonly SessionStore $session, private readonly \Closure $db, private readonly Clock $clock) {}

    public function csrfToken(): string
    {
        $this->session->start();
        $data = &$this->session->data();
        return SessionSecurity::token($data);
    }

    public function verifyCsrf(Request $request): void
    {
        $this->session->start();
        SessionSecurity::verifyCsrf($this->session->data(), $request->header('x-csrf-token'));
    }

    /** @return array{id:int,email:string,display_name:string} active admin */
    public function admin(Request $request): array
    {
        $this->session->start();
        $data = &$this->session->data();
        $id = SessionSecurity::authenticated($data, $this->clock->now()->getTimestamp());
        if ($request->method !== 'GET') {
            SessionSecurity::verifyCsrf($data, $request->header('x-csrf-token'));
        }
        $admin = (new AdminRepository(($this->db)(), $this->clock))->find($id);
        if ($admin === null || !$admin['is_active']) {
            // Deactivated or removed accounts lose existing sessions immediately.
            $this->session->destroy();
            throw new ApiException(401, 'unauthorized', 'Please sign in.');
        }
        return $admin;
    }

    /** Fresh session ID and CSRF token; pre-login session data is discarded. */
    public function signIn(int $adminId): void
    {
        $this->session->regenerate();
        $data = &$this->session->data();
        $now = $this->clock->now()->getTimestamp();
        $data = ['admin_id' => $adminId, 'authenticated_at' => $now, 'last_seen' => $now];
        SessionSecurity::token($data);
    }

    public function signOut(): void
    {
        $this->session->destroy();
    }
}
