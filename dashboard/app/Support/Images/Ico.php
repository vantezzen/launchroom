<?php

declare(strict_types=1);

namespace App\Support\Images;

/**
 * Converts a Windows icon (`favicon.ico`) to PNG: the best entry is picked
 * (largest, then deepest colour), embedded PNGs are re-encoded by
 * {@see Png}, and classic bitmaps (1/4/8/24/32 bit with the AND mask) are
 * decoded to RGBA and written as a new PNG. Browsers never see the ICO.
 */
final class Ico
{
    public const string SIGNATURE = "\x00\x00\x01\x00";

    private const int MAX_ENTRIES = 64;

    /**
     * @throws InvalidImage
     */
    public static function toPng(string $bytes): string
    {
        $length = strlen($bytes);

        if ($length < 6 || ! str_starts_with($bytes, self::SIGNATURE)) {
            throw InvalidImage::because('This is not an ICO image.');
        }

        $count = self::u16($bytes, 4);

        if ($count < 1 || $count > self::MAX_ENTRIES || $length < 6 + 16 * $count) {
            throw InvalidImage::because('The ICO image is damaged.');
        }

        $entries = [];

        for ($i = 0; $i < $count; $i++) {
            $at = 6 + 16 * $i;
            $size = self::u32($bytes, $at + 8);
            $offset = self::u32($bytes, $at + 12);

            if ($size < 40 || $offset < 6 + 16 * $count || $offset > $length || $size > $length - $offset) {
                continue;
            }

            $entries[] = [
                'side' => ord($bytes[$at]) === 0 ? 256 : ord($bytes[$at]),
                'bits' => self::u16($bytes, $at + 6),
                'data' => substr($bytes, $offset, $size),
            ];
        }

        usort($entries, static fn (array $a, array $b): int => [$b['side'], $b['bits']] <=> [$a['side'], $a['bits']]);

        foreach ($entries as $entry) {
            try {
                return str_starts_with($entry['data'], Png::SIGNATURE)
                    ? Png::normalize($entry['data'], 256)['bytes']
                    : self::bitmap($entry['data']);
            } catch (InvalidImage) {
                // Try the next entry.
            }
        }

        throw InvalidImage::because('The ICO image is damaged.');
    }

    /**
     * A BITMAPINFOHEADER image with XOR bitmap and AND mask (bottom-up).
     */
    private static function bitmap(string $dib): string
    {
        $length = strlen($dib);
        $headerSize = self::u32($dib, 0);

        if ($headerSize < 40 || $headerSize > $length) {
            throw InvalidImage::because('The ICO image is damaged.');
        }

        /** @var array{width: int, height: int, planes: int, bits: int, compression: int, colors: int} $info */
        $info = unpack('Vwidth/Vheight/vplanes/vbits/Vcompression/x12/Vcolors', $dib, 4);
        $width = $info['width'];
        $height = intdiv($info['height'], 2);
        $bits = $info['bits'];

        if ($width < 1 || $width > 256 || $height < 1 || $height > 256 || $info['compression'] !== 0 || ! in_array($bits, [1, 4, 8, 24, 32], true)) {
            throw InvalidImage::because('The ICO image uses features we do not support.');
        }

        $offset = $headerSize;
        $palette = [];

        if ($bits <= 8) {
            $colors = $info['colors'] === 0 ? 1 << $bits : min($info['colors'], 1 << $bits);

            if ($offset + 4 * $colors > $length) {
                throw InvalidImage::because('The ICO image is damaged.');
            }

            for ($i = 0; $i < $colors; $i++) {
                $at = $offset + 4 * $i;
                $palette[] = $dib[$at + 2].$dib[$at + 1].$dib[$at];
            }

            $offset += 4 * $colors;
        }

        $stride = intdiv($bits * $width + 31, 32) * 4;
        $maskStride = intdiv($width + 31, 32) * 4;

        if ($offset + $stride * $height > $length) {
            throw InvalidImage::because('The ICO image is damaged.');
        }

        $maskOffset = $offset + $stride * $height;
        $hasMask = $maskOffset + $maskStride * $height <= $length;
        $rgba = '';
        $anyAlpha = false;

        for ($y = 0; $y < $height; $y++) {
            $row = $offset + ($height - 1 - $y) * $stride;
            $maskRow = $maskOffset + ($height - 1 - $y) * $maskStride;

            for ($x = 0; $x < $width; $x++) {
                $masked = $hasMask && ((ord($dib[$maskRow + ($x >> 3)]) >> (7 - ($x & 7))) & 1) === 1;

                if ($bits === 32) {
                    $at = $row + 4 * $x;
                    $alpha = ord($dib[$at + 3]);
                    $anyAlpha = $anyAlpha || $alpha > 0;
                    $rgba .= $dib[$at + 2].$dib[$at + 1].$dib[$at].($masked && $alpha === 0 ? "\x00" : chr($alpha));
                } elseif ($bits === 24) {
                    $at = $row + 3 * $x;
                    $rgba .= $dib[$at + 2].$dib[$at + 1].$dib[$at].($masked ? "\x00" : "\xFF");
                } else {
                    $byte = ord($dib[$row + intdiv($x * $bits, 8)]);
                    $shift = 8 - $bits - (($x * $bits) % 8);
                    $index = ($byte >> $shift) & ((1 << $bits) - 1);
                    $rgba .= ($palette[$index] ?? "\x00\x00\x00").($masked ? "\x00" : "\xFF");
                }
            }
        }

        // Old 32-bit icons leave the alpha channel empty and rely on the mask.
        if ($bits === 32 && ! $anyAlpha) {
            $opaque = '';

            for ($i = 0, $pixels = $width * $height; $i < $pixels; $i++) {
                $x = $i % $width;
                $y = intdiv($i, $width);
                $maskRow = $maskOffset + ($height - 1 - $y) * $maskStride;
                $masked = $hasMask && ((ord($dib[$maskRow + ($x >> 3)]) >> (7 - ($x & 7))) & 1) === 1;
                $opaque .= substr($rgba, $i * 4, 3).($masked ? "\x00" : "\xFF");
            }

            $rgba = $opaque;
        }

        return Png::fromRgba($width, $height, $rgba);
    }

    private static function u16(string $bytes, int $offset): int
    {
        /** @var array{1: int} $value */
        $value = unpack('v', $bytes, $offset);

        return $value[1];
    }

    private static function u32(string $bytes, int $offset): int
    {
        /** @var array{1: int} $value */
        $value = unpack('V', $bytes, $offset);

        return $value[1];
    }
}
