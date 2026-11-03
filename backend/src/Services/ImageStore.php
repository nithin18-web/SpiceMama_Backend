<?php
declare(strict_types=1);
namespace SpiceMama\Services;
use SpiceMama\Http\ApiException;
use SpiceMama\Support\Paths;

/**
 * Generated product-image files. Processing happens in private staging; only
 * re-encoded files are moved into the public directory. Names are always
 * server-generated and validated before deletion; nothing outside the upload
 * directory (such as repository Images/) can be targeted.
 */
final class ImageStore
{
    private const NAME = '/^[a-f0-9]{32}\.(?:jpg|png|webp)$/D';

    public function __construct(private readonly string $uploadDirectory, private readonly string $stagingDirectory)
    {
        if (!Paths::isAbsolute($uploadDirectory) || !Paths::isAbsolute($stagingDirectory)) {
            throw new ApiException(503, 'unavailable', 'Image storage is not configured.');
        }
    }

    public function stagingPath(): string
    {
        self::directory($this->stagingDirectory, 0700);
        return $this->stagingDirectory . '/' . bin2hex(random_bytes(16)) . '.tmp';
    }

    /** Moves a processed file into the public directory and returns its stored name. */
    public function publish(string $staged, string $ext): string
    {
        self::directory($this->uploadDirectory, 0755);
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        $target = $this->uploadDirectory . '/' . $name;
        if (file_exists($target)) {
            throw new \RuntimeException('Generated image name collision.');
        }
        if (!@rename($staged, $target)) {
            // Staging and public directories may be on different filesystems.
            if (!copy($staged, $target)) {
                throw new \RuntimeException('Cannot store image.');
            }
            unlink($staged);
        }
        chmod($target, 0644);
        return $name;
    }

    public function delete(string $name): bool
    {
        if (!preg_match(self::NAME, $name)) {
            return false;
        }
        $path = $this->uploadDirectory . '/' . $name;
        if (!is_file($path)) {
            return true;
        }
        try {
            return unlink($path);
        } catch (\ErrorException) {
            return false;
        }
    }

    /** @return list<string> generated files currently on disk */
    public function files(): array
    {
        return is_dir($this->uploadDirectory) ? array_values(array_filter(scandir($this->uploadDirectory), fn(string $file): bool => (bool) preg_match(self::NAME, $file))) : [];
    }

    private static function directory(string $path, int $mode): void
    {
        if (!is_dir($path) && !mkdir($path, $mode, true) && !is_dir($path)) {
            throw new \RuntimeException('Storage directory unavailable.');
        }
    }
}
