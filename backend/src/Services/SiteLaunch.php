<?php
declare(strict_types=1);
namespace SpiceMama\Services;
use PDO;
use SpiceMama\Http\ApiException;
use SpiceMama\Support\{Clock, Paths};

/**
 * Creates the marker file that public_html/.htaccess checks (RewriteCond .../.launched !-f).
 * Once it exists every visitor gets the real site instead of the pre-launch page; deleting the
 * file brings the page back. Shared by the public and the admin launch endpoints.
 */
final class SiteLaunch
{
    /** @param \Closure():PDO $db @return array{launched:bool,launched_at:string} */
    public static function open(array $config, \Closure $db, Clock $clock, ?int $adminId, string $via): array
    {
        $flag = self::flagPath($config);
        if (!is_file($flag)) {
            // Exclusive create: a second click (or a race) never rewrites the original launch time.
            $handle = @fopen($flag, 'x');
            if ($handle === false && !is_file($flag)) {
                throw new \RuntimeException('Cannot create launch marker.');
            }
            if ($handle !== false) {
                fwrite($handle, $clock->db() . "\n");
                fclose($handle);
                chmod($flag, 0644);
                AuditLogger::record($db(), $adminId, 'site.launched', 'site', null, ['via' => $via], $clock->db());
            }
        }
        return ['launched' => true, 'launched_at' => trim((string) file_get_contents($flag))];
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
