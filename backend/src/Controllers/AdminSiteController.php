<?php
declare(strict_types=1);
namespace SpiceMama\Controllers;
use SpiceMama\Http\{ApiException, Request, Response};
use SpiceMama\Middleware\AdminGuard;
use SpiceMama\Services\AuditLogger;
use SpiceMama\Support\{Clock, Paths};
use SpiceMama\Validation\AdminInput;

/**
 * Site launch: an admin creates the marker file that public_html/.htaccess checks
 * (RewriteCond %{DOCUMENT_ROOT}/.launched !-f). Once it exists every visitor gets the
 * real site instead of the pre-launch page. Deleting the file brings the page back.
 */
final class AdminSiteController
{
    public function __construct(private readonly AdminGuard $guard, private readonly \Closure $db, private readonly Clock $clock, private readonly array $config) {}

    public function launch(Request $request): Response
    {
        $admin = $this->guard->admin($request);
        AdminInput::noQuery($request->query);
        $flag = self::flagPath($this->config);
        if (!is_file($flag)) {
            // Exclusive create: a second click (or a race) never rewrites the original launch time.
            $handle = @fopen($flag, 'x');
            if ($handle === false && !is_file($flag)) {
                throw new \RuntimeException('Cannot create launch marker.');
            }
            if ($handle !== false) {
                fwrite($handle, $this->clock->db() . "\n");
                fclose($handle);
                chmod($flag, 0644);
                AuditLogger::record(($this->db)(), $admin['id'], 'site.launched', 'site', null, null, $this->clock->db());
            }
        }
        return new Response(200, ['data' => ['launched' => true, 'launched_at' => trim((string) file_get_contents($flag))]]);
    }

    /** `launch_flag` if configured, else public_html/.launched derived from upload_path (public_html/uploads/products). */
    public static function flagPath(array $config): string
    {
        $flag = (string) ($config['launch_flag'] ?? '');
        if ($flag === '') {
            $uploads = rtrim((string) ($config['upload_path'] ?? ''), '/\\');
            $flag = $uploads === '' ? '' : dirname($uploads, 2) . '/.launched';
        }
        if (!Paths::isAbsolute($flag)) {
            throw new ApiException(503, 'unavailable', 'Launch is not configured.');
        }
        return $flag;
    }
}
