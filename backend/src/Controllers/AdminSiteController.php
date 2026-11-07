<?php
declare(strict_types=1);
namespace SpiceMama\Controllers;
use SpiceMama\Http\{ApiException, Request, Response};
use SpiceMama\Middleware\AdminGuard;
use SpiceMama\Services\SiteLaunch;
use SpiceMama\Support\Clock;
use SpiceMama\Validation\AdminInput;

/** Site launch: see Services\SiteLaunch. */
final class AdminSiteController
{
    public function __construct(private readonly AdminGuard $guard, private readonly \Closure $db, private readonly Clock $clock, private readonly array $config) {}

    /** POST /admin/launch — signed-in admin (session + CSRF). */
    public function launch(Request $request): Response
    {
        $admin = $this->guard->admin($request);
        AdminInput::noQuery($request->query);
        return new Response(200, ['data' => SiteLaunch::open($this->config, $this->db, $this->clock, $admin['id'], 'admin')]);
    }

    /**
     * POST /launch — the public Launch button on the pre-launch page (owner decision 2026-11-07:
     * anyone may press it). No session; the browser's Origin must be this site, so another
     * website cannot trigger the launch from a visitor's browser.
     */
    public function launchPublic(Request $request): Response
    {
        AdminInput::noQuery($request->query);
        $origin = (string) $request->header('origin');
        $host = strtolower((string) $request->header('host'));
        $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST) . (parse_url($origin, PHP_URL_PORT) ? ':' . parse_url($origin, PHP_URL_PORT) : ''));
        if ($origin === '' || $host === '' || $originHost !== $host) {
            throw new ApiException(403, 'forbidden', 'Launch must be pressed on the website itself.');
        }
        return new Response(200, ['data' => SiteLaunch::open($this->config, $this->db, $this->clock, null, 'public')]);
    }
}
