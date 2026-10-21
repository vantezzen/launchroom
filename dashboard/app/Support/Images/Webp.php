<?php

declare(strict_types=1);

namespace App\Support\Images;

/**
 * WebP without an image extension: the RIFF container is parsed chunk by
 * chunk and written again with only the bitstream chunks (VP8, VP8L, VP8X,
 * ALPH). Metadata (EXIF, XMP, ICC), unknown chunks and trailing bytes are
 * dropped, the VP8/VP8L frame headers are checked and the size limit is
 * enforced. Animations are refused.
 */
final class Webp
{
    private const array KEEP = ['VP8 ', 'VP8L', 'VP8X', 'ALPH'];

    private const int MAX_CHUNKS = 64;

    /**
     * @return array{bytes: string, width: int, height: int}
     *
     * @throws InvalidImage
     */
    public static function normalize(string $bytes, int $maxSide = 1024): array
    {
        if (strlen($bytes) < 20 || ! str_starts_with($bytes, 'RIFF') || substr($bytes, 8, 4) !== 'WEBP') {
            throw InvalidImage::because('This is not a WebP image.');
        }

        $end = min(strlen($bytes), 8 + self::u32($bytes, 4));
        $offset = 12;
        $chunks = [];
        $size = null;

        while ($offset + 8 <= $end) {
            if (count($chunks) >= self::MAX_CHUNKS) {
                throw InvalidImage::because('The WebP image is damaged.');
            }

            $type = substr($bytes, $offset, 4);
            $length = self::u32($bytes, $offset + 4);

            if ($length > $end - $offset - 8) {
                throw InvalidImage::because('The WebP image is damaged.');
            }

            $data = substr($bytes, $offset + 8, $length);
            $offset += 8 + $length + ($length & 1);

            if (in_array($type, ['ANIM', 'ANMF'], true)) {
                throw InvalidImage::because('Animated images are not supported.');
            }

            if (! in_array($type, self::KEEP, true)) {
                continue;
            }

            if ($type === 'VP8X') {
                if ($length < 10) {
                    throw InvalidImage::because('The WebP image is damaged.');
                }

                // Clear the ICC, EXIF and XMP flags: those chunks are gone.
                $data[0] = chr(ord($data[0]) & ~(0x20 | 0x08 | 0x04));
            } elseif ($type !== 'ALPH') {
                if ($size !== null) {
                    throw InvalidImage::because('The WebP image is damaged.');
                }

                $size = $type === 'VP8L' ? self::losslessSize($data) : self::lossySize($data);
            }

            $chunks[] = [$type, $data];
        }

        if ($size === null) {
            throw InvalidImage::because('The WebP image is damaged.');
        }

        [$width, $height] = $size;

        if ($width < 1 || $height < 1 || $width > $maxSide || $height > $maxSide) {
            throw InvalidImage::because("The image must be at most {$maxSide}×{$maxSide} pixels.");
        }

        $body = 'WEBP';

        foreach ($chunks as [$type, $data]) {
            $body .= $type.pack('V', strlen($data)).$data.(strlen($data) % 2 === 1 ? "\x00" : '');
        }

        return ['bytes' => 'RIFF'.pack('V', strlen($body)).$body, 'width' => $width, 'height' => $height];
    }

    /**
     * @return array{int, int}
     */
    private static function lossySize(string $data): array
    {
        // Frame tag (3 bytes, key frame bit clear), start code, 14-bit sizes.
        if (strlen($data) < 10 || (ord($data[0]) & 1) !== 0 || substr($data, 3, 3) !== "\x9d\x01\x2a") {
            throw InvalidImage::because('The WebP image is damaged.');
        }

        /** @var array{w: int, h: int} $size */
        $size = unpack('vw/vh', $data, 6);

        return [$size['w'] & 0x3FFF, $size['h'] & 0x3FFF];
    }

    /**
     * @return array{int, int}
     */
    private static function losslessSize(string $data): array
    {
        if (strlen($data) < 5 || ord($data[0]) !== 0x2F) {
            throw InvalidImage::because('The WebP image is damaged.');
        }

        $bits = self::u32($data, 1);

        return [($bits & 0x3FFF) + 1, (($bits >> 14) & 0x3FFF) + 1];
    }

    private static function u32(string $bytes, int $offset): int
    {
        /** @var array{1: int} $value */
        $value = unpack('V', $bytes, $offset);

        return $value[1];
    }
}
