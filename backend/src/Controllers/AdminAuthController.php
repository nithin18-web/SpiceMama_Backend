<?php
declare(strict_types=1);
namespace SpiceMama\Controllers;
use SpiceMama\Http\{ApiException, Request, Response};
use SpiceMama\Middleware\AdminGuard;
use SpiceMama\Repositories\AdminRepository;
use SpiceMama\Services\LoginThrottle;
use SpiceMama\Support\Clock;
use SpiceMama\Validation\AdminInput;

final class AdminAuthController
{
    /** Verified against unknown emails so response time does not reveal which accounts exist. */
    private const DUMMY_HASH = '$2y$12$HBZ4G.aZeGcVUZnSX.jf9uYD3d2mzNZLnEOdn/LjFLuJrRFwRGbzi';

    public function __construct(
        private readonly AdminGuard $guard,
        private readonly \Closure $db,
        private readonly Clock $clock,
        private readonly \Closure $throttle,
    ) {}

    public function csrf(Request $request): Response
    {
        AdminInput::noQuery($request->query);
        return new Response(200, ['data' => ['csrf_token' => $this->guard->csrfToken()]]);
    }

    public function login(Request $request): Response
    {
        $this->guard->verifyCsrf($request);
        $input = AdminInput::login($request->json());
        /** @var LoginThrottle $throttle */
        $throttle = ($this->throttle)();
        $throttle->attempt($input['email'], $request->clientIp);
        $admins = new AdminRepository(($this->db)(), $this->clock);
        $admin = $admins->findByEmail($input['email']);
        $valid = password_verify($input['password'], $admin['password_hash'] ?? self::DUMMY_HASH);
        if (!$valid || $admin === null || !$admin['is_active']) {
            throw new ApiException(401, 'unauthorized', 'Email or password is incorrect.');
        }
        $admins->recordLogin($admin['id'], $input['password'], $admin['password_hash']);
        $throttle->reset($input['email']);
        $this->guard->signIn($admin['id']);
        return new Response(200, ['data' => AdminRepository::public($admin)]);
    }

    public function me(Request $request): Response
    {
        AdminInput::noQuery($request->query);
        return new Response(200, ['data' => AdminRepository::public($this->guard->admin($request))]);
    }

    public function logout(Request $request): Response
    {
        $admin = $this->guard->admin($request);
        (new AdminRepository(($this->db)(), $this->clock))->recordLogout($admin['id']);
        $this->guard->signOut();
        return new Response(204);
    }
}
