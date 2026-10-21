<?php

declare(strict_types=1);

namespace App\Support\Images;

/**
 * PNG re-encoding without an image extension: every chunk is parsed and
 * CRC-checked, the pixel stream is inflated (bounded by the size IHDR
 * implies, so no zip bombs), its scanline filters are checked, and a new
 * file is written with our own IHDR, PLTE/tRNS and one freshly deflated
 * IDAT. Text, metadata, APNG frames and anything after IEND are dropped, so
 * the stored file contains nothing but pixels we decoded.
 */
final class Png
{
    public const string SIGNATURE = "\x89PNG\r\n\x1a\n";

    /** colour type => [channels, allowed bit depths] */
    private const array COLOR_TYPES = [
        0 => [1, [1, 2, 4, 8, 16]],
        2 => [3, [8, 16]],
        3 => [1, [1, 2, 4, 8]],
        4 => [2, [8, 16]],
        6 => [4, [8, 16]],
    ];

    /** Adam7 passes: [x start, y start, x step, y step]. */
    private const array ADAM7 = [[0, 0, 8, 8], [4, 0, 8, 8], [0, 4, 4, 8], [2, 0, 4, 4], [0, 2, 2, 4], [1, 0, 2, 2], [0, 1, 1, 2]];

    private const int MAX_CHUNKS = 4096;

    /**
     * @return array{bytes: string, width: int, height: int}
     *
     * @throws InvalidImage
     */
    public static function normalize(string $bytes, int $maxSide = 1024): array
    {
        if (! str_starts_with($bytes, self::SIGNATURE)) {
            throw InvalidImage::because('This is not a PNG image.');
        }

        $offset = 8;
        $length = strlen($bytes);
        $header = null;
        $palette = null;
        $transparency = null;
        $data = '';
        $ended = false;

        for ($chunks = 0; $offset < $length && ! $ended; $chunks++) {
            if ($chunks >= self::MAX_CHUNKS || $offset + 12 > $length) {
                throw InvalidImage::because('The PNG image is damaged.');
            }

            $size = self::u32($bytes, $offset);
            $type = substr($bytes, $offset + 4, 4);

            if ($size > $length - $offset - 12 || preg_match('/^[A-Za-z]{4}$/', $type) !== 1) {
                throw InvalidImage::because('The PNG image is damaged.');
            }

            $body = substr($bytes, $offset + 8, $size);

            if (self::u32($bytes, $offset + 8 + $size) !== crc32($type.$body)) {
                throw InvalidImage::because('The PNG image is damaged.');
            }

            $offset += 12 + $size;

            if ($header === null && $type !== 'IHDR') {
                throw InvalidImage::because('The PNG image is damaged.');
            }

            switch ($type) {
                case 'IHDR':
                    if ($header !== null || $size !== 13) {
                        throw InvalidImage::because('The PNG image is damaged.');
                    }
                    $header = $body;
                    break;
                case 'PLTE':
                    if ($palette !== null || $size === 0 || $size % 3 !== 0 || $size > 768 || $data !== '') {
                        throw InvalidImage::because('The PNG image is damaged.');
                    }
                    $palette = $body;
                    break;
                case 'tRNS':
                    $transparency = $body;
                    break;
                case 'IDAT':
                    $data .= $body;
                    break;
                case 'IEND':
                    $ended = true;
                    break;
                default:
                    // Ancillary chunks (text, metadata, colour profiles,
                    // APNG frames) are dropped. Unknown critical ones can't
                    // be decoded safely.
                    if (ctype_upper($type[0])) {
                        throw InvalidImage::because('The PNG image uses features we do not support.');
                    }
            }
        }

        if ($header === null || ! $ended || $data === '') {
            throw InvalidImage::because('The PNG image is damaged.');
        }

        /** @var array{width: int, height: int, depth: int, color: int, compression: int, filter: int, interlace: int} $ihdr */
        $ihdr = unpack('Nwidth/Nheight/Cdepth/Ccolor/Ccompression/Cfilter/Cinterlace', $header);
        [$width, $height, $depth, $color] = [$ihdr['width'], $ihdr['height'], $ihdr['depth'], $ihdr['color']];

        if ($width < 1 || $height < 1 || $width > $maxSide || $height > $maxSide) {
            throw InvalidImage::because("The image must be at most {$maxSide}×{$maxSide} pixels.");
        }

        if (! isset(self::COLOR_TYPES[$color]) || ! in_array($depth, self::COLOR_TYPES[$color][1], true)
            || $ihdr['compression'] !== 0 || $ihdr['filter'] !== 0 || $ihdr['interlace'] > 1) {
            throw InvalidImage::because('The PNG image is damaged.');
        }

        if ($color === 3 && $palette === null) {
            throw InvalidImage::because('The PNG image is damaged.');
        }

        if ($transparency !== null && ! self::validTransparency($transparency, $color, $palette)) {
            $transparency = null;
        }

        $bitsPerPixel = self::COLOR_TYPES[$color][0] * $depth;
        $rows = self::rows($width, $height, $bitsPerPixel, $ihdr['interlace'] === 1);
        $expected = array_sum(array_map(static fn (array $row): int => $row[0] * (1 + $row[1]), $rows));
        $pixels = self::inflate($data, $expected);

        // Every scanline starts with a filter type 0–4.
        $position = 0;

        foreach ($rows as [$count, $rowBytes]) {
            for ($i = 0; $i < $count; $i++) {
                if (ord($pixels[$position]) > 4) {
                    throw InvalidImage::because('The PNG image is damaged.');
                }

                $position += 1 + $rowBytes;
            }
        }

        $out = self::SIGNATURE.self::chunk('IHDR', $header);

        if ($palette !== null && in_array($color, [2, 3, 6], true)) {
            $out .= self::chunk('PLTE', $palette);
        }

        if ($transparency !== null) {
            $out .= self::chunk('tRNS', $transparency);
        }

        $out .= self::chunk('IDAT', self::deflate($pixels)).self::chunk('IEND', '');

        return ['bytes' => $out, 'width' => $width, 'height' => $height];
    }

    /**
     * A truecolour + alpha PNG from raw RGBA bytes (top-down rows).
     */
    public static function fromRgba(int $width, int $height, string $rgba): string
    {
        $raw = '';
        $stride = $width * 4;

        for ($y = 0; $y < $height; $y++) {
            $raw .= "\x00".substr($rgba, $y * $stride, $stride);
        }

        return self::SIGNATURE
            .self::chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
            .self::chunk('IDAT', self::deflate($raw))
            .self::chunk('IEND', '');
    }

    /**
     * Scanline groups as [row count, bytes per row] (one per Adam7 pass).
     *
     * @return list<array{int, int}>
     */
    private static function rows(int $width, int $height, int $bitsPerPixel, bool $interlaced): array
    {
        $bytes = static fn (int $pixels): int => intdiv($pixels * $bitsPerPixel + 7, 8);

        if (! $interlaced) {
            return [[$height, $bytes($width)]];
        }

        $rows = [];

        foreach (self::ADAM7 as [$x0, $y0, $dx, $dy]) {
            $passWidth = intdiv($width - $x0 + $dx - 1, $dx);
            $passHeight = intdiv($height - $y0 + $dy - 1, $dy);

            if ($passWidth > 0 && $passHeight > 0) {
                $rows[] = [$passHeight, $bytes($passWidth)];
            }
        }

        return $rows;
    }

    private static function validTransparency(string $chunk, int $color, ?string $palette): bool
    {
        return match ($color) {
            0 => strlen($chunk) === 2,
            2 => strlen($chunk) === 6,
            3 => $palette !== null && strlen($chunk) <= intdiv(strlen($palette), 3),
            default => false,
        };
    }

    /**
     * Inflates the zlib stream, refusing anything that isn't exactly the
     * size the header implies.
     */
    private static function inflate(string $data, int $expected): string
    {
        set_error_handler(static fn (): bool => true);

        try {
            $pixels = gzuncompress($data, $expected + 1);
        } finally {
            restore_error_handler();
        }

        if (! is_string($pixels) || strlen($pixels) !== $expected) {
            throw InvalidImage::because('The PNG image is damaged.');
        }

        return $pixels;
    }

    private static function deflate(string $raw): string
    {
        $compressed = gzcompress($raw, 9);

        if (! is_string($compressed)) {
            throw InvalidImage::because('The image could not be encoded.');
        }

        return $compressed;
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }

    private static function u32(string $bytes, int $offset): int
    {
        /** @var array{1: int} $value */
        $value = unpack('N', $bytes, $offset);

        return $value[1];
    }
}
