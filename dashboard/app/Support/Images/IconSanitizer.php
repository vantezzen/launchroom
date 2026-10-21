<?php

declare(strict_types=1);

namespace App\Support\Images;

/**
 * The one way into project icon storage, for fetched favicons and uploads
 * alike (docs/09 R32): the format is detected from the bytes (never from a
 * file name or Content-Type), only PNG, ICO, SVG and WebP are accepted, and
 * what comes out is always re-encoded or rebuilt by us: PNG and ICO become
 * a new PNG, WebP a rebuilt container, SVG a reconstructed document.
 */
final class IconSanitizer
{
    public const int MAX_BYTES = 256 * 1024;

    /**
     * @throws InvalidImage
     */
    public static function sanitize(string $bytes): IconImage
    {
        if ($bytes === '') {
            throw InvalidImage::because('The image is empty.');
        }

        if (strlen($bytes) > self::MAX_BYTES) {
            throw InvalidImage::because('The image must be smaller than 256 KB.');
        }

        return match (true) {
            str_starts_with($bytes, Png::SIGNATURE) => new IconImage(Png::normalize($bytes)['bytes'], 'png'),
            str_starts_with($bytes, Ico::SIGNATURE) => new IconImage(Ico::toPng($bytes), 'png'),
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => new IconImage(Webp::normalize($bytes)['bytes'], 'webp'),
            self::looksLikeSvg($bytes) => new IconImage(Svg::sanitize($bytes), 'svg'),
            default => throw InvalidImage::because('Use a PNG, ICO, SVG or WebP image.'),
        };
    }

    private static function looksLikeSvg(string $bytes): bool
    {
        $head = ltrim(substr($bytes, 0, 2048), "\xEF\xBB\xBF \t\r\n");
        // Skip an XML declaration and leading comments.
        $head = preg_replace('/^(?:<\?xml[^>]*\?>\s*|<!--.*?-->\s*)+/s', '', $head) ?? '';

        return preg_match('/^<svg[\s>]/i', $head) === 1;
    }
}
