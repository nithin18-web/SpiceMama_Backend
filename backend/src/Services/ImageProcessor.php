<?php
declare(strict_types=1);
namespace SpiceMama\Services;
use SpiceMama\Http\ApiException;

/**
 * Content-based validation and GD re-encoding. Re-encoding drops metadata
 * (EXIF/GPS) and any payload appended to the image data. EXIF orientation is
 * not applied: rotating would double peak memory on 64 MB hosting.
 */
final class ImageProcessor
{
    public const MAX_SIDE = 4096;
    public const MAX_PIXELS = 4000000;
    private const TYPES = [
        'image/jpeg' => [IMAGETYPE_JPEG, 'jpg', IMG_JPG],
        'image/png' => [IMAGETYPE_PNG, 'png', IMG_PNG],
        'image/webp' => [IMAGETYPE_WEBP, 'webp', IMG_WEBP],
    ];

    /** @return array{mime:string,ext:string,width:int,height:int,bytes:int} */
    public function process(string $source, string $target): array
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($source);
        if (!is_string($mime) || !isset(self::TYPES[$mime]) || !(imagetypes() & self::TYPES[$mime][2])) {
            throw new ApiException(415, 'unsupported_media_type', 'Upload a JPEG, PNG or WebP image.', ['file' => 'Use JPEG, PNG or WebP.']);
        }
        [$type, $ext] = self::TYPES[$mime];
        $info = $this->quietly(fn() => getimagesize($source));
        if (!is_array($info) || $info[2] !== $type) {
            throw self::invalid('The image could not be read.');
        }
        [$width, $height] = $info;
        if ($width < 1 || $height < 1 || $width > self::MAX_SIDE || $height > self::MAX_SIDE || $width * $height > self::MAX_PIXELS) {
            throw self::invalid('Each side must be at most 4096 pixels and the image at most 4,000,000 pixels.');
        }
        if ($this->animated($source, $mime)) {
            throw self::invalid('Animated images are not supported.');
        }
        $this->ensureMemory($width * $height);
        $image = $this->quietly(fn() => match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($source),
            'image/png' => imagecreatefrompng($source),
            'image/webp' => imagecreatefromwebp($source),
        });
        if (!$image instanceof \GdImage || imagesx($image) !== $width || imagesy($image) !== $height) {
            throw self::invalid('The image could not be processed.');
        }
        try {
            if ($mime !== 'image/jpeg') {
                imagesavealpha($image, true);
            }
            $written = match ($mime) {
                'image/jpeg' => imagejpeg($image, $target, 85),
                'image/png' => imagepng($image, $target, 6),
                'image/webp' => imagewebp($image, $target, 85),
            };
        } finally {
            unset($image);
        }
        clearstatcache(true, $target);
        if (!$written || !is_file($target) || filesize($target) < 1) {
            throw new \RuntimeException('Image encoding failed.');
        }
        return ['mime' => $mime, 'ext' => $ext, 'width' => $width, 'height' => $height, 'bytes' => filesize($target)];
    }

    private function animated(string $source, string $mime): bool
    {
        $head = (string) file_get_contents($source, false, null, 0, 65536);
        if ($mime === 'image/png') {
            // APNG declares acTL before the first IDAT chunk.
            $actl = strpos($head, 'acTL');
            return $actl !== false && ($actl < (strpos($head, 'IDAT') ?: PHP_INT_MAX));
        }
        if ($mime === 'image/webp') {
            // VP8X header flag bit 1 marks animation.
            return substr($head, 12, 4) === 'VP8X' && (ord($head[20] ?? "\0") & 0x02) !== 0;
        }
        return false;
    }

    /** Truecolor GD images need roughly 5 bytes per pixel plus working space. */
    private function ensureMemory(int $pixels): void
    {
        $limit = self::bytes((string) ini_get('memory_limit'));
        if ($limit > 0 && memory_get_usage(true) + $pixels * 5 + 4194304 > $limit) {
            throw self::invalid('The image is too large to process. Use smaller dimensions.');
        }
    }

    /** Converts php.ini shorthand such as 8M; -1 or 0 means unlimited. */
    public static function bytes(string $value): int
    {
        $value = trim($value);
        $number = (int) $value;
        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1073741824,
            'm' => $number * 1048576,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /** Decoder warnings on corrupt input are rejected uploads, not server errors. */
    private function quietly(\Closure $work): mixed
    {
        try {
            return $work();
        } catch (\ErrorException|\ValueError) {
            return false;
        }
    }

    private static function invalid(string $message): ApiException
    {
        return new ApiException(422, 'validation_failed', $message, ['file' => $message]);
    }
}
