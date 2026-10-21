<?php

declare(strict_types=1);

use App\Support\Images\Ico;
use App\Support\Images\IconImage;
use App\Support\Images\IconSanitizer;
use App\Support\Images\InvalidImage;
use App\Support\Images\Png;
use App\Support\Images\Webp;

/** A PNG chunk with its CRC. */
function iconChunk(string $type, string $data): string
{
    return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
}

/** A 2×2 RGBA PNG, optionally with extra chunks before IEND. */
function tinyPng(string $extra = '', int $width = 2, int $height = 2): string
{
    $raw = str_repeat("\x00".str_repeat("\xFF\x00\x00\xFF", $width), $height);

    return Png::SIGNATURE
        .iconChunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
        .$extra
        .iconChunk('IDAT', (string) gzcompress($raw))
        .iconChunk('IEND', '');
}

/** A 2×2 32-bit ICO bitmap entry (bottom-up BGRA plus AND mask). */
function tinyIco(): string
{
    $dib = pack('VVVvvVVVVVV', 40, 2, 4, 1, 32, 0, 0, 0, 0, 0, 0)
        .str_repeat("\x00\x00\xFF\xFF", 4) // red, opaque
        .str_repeat("\x00\x00\x00\x00", 2); // AND mask rows

    return Ico::SIGNATURE.pack('v', 1)
        .pack('CCCCvvVV', 2, 2, 0, 0, 1, 32, strlen($dib), 22)
        .$dib;
}

test('PNGs are re-encoded: metadata and trailing bytes are dropped', function (): void {
    $input = tinyPng(iconChunk('tEXt', "Comment\x00<script>alert(1)</script>")).'<html><script>alert(2)</script>';

    $icon = IconSanitizer::sanitize($input);

    expect($icon->extension)->toBe('png')
        ->and($icon->contentType())->toBe('image/png')
        ->and($icon->bytes)->not->toContain('script')
        ->and($icon->bytes)->not->toContain('tEXt')
        ->and(str_ends_with($icon->bytes, iconChunk('IEND', '')))->toBeTrue()
        // What we wrote decodes to the same pixels.
        ->and(Png::normalize($icon->bytes)['width'])->toBe(2);
});

test('damaged or oversized PNGs are refused', function (string $bytes): void {
    expect(fn (): IconImage => IconSanitizer::sanitize($bytes))->toThrow(InvalidImage::class);
})->with([
    'bad CRC' => fn (): string => substr_replace(tinyPng(), "\x00\x00\x00\x00", 29, 4),
    'truncated' => fn (): string => substr(tinyPng(), 0, 40),
    'too large' => fn (): string => tinyPng('', 2048, 1),
    // IHDR says 2×2 but the stream inflates to much more (zip bomb).
    'pixel stream bigger than the header' => fn (): string => Png::SIGNATURE
        .iconChunk('IHDR', pack('NNCCCCC', 2, 2, 8, 6, 0, 0, 0))
        .iconChunk('IDAT', (string) gzcompress(str_repeat("\x00", 1_000_000)))
        .iconChunk('IEND', ''),
]);

test('ICO bitmaps become a new PNG', function (): void {
    $icon = IconSanitizer::sanitize(tinyIco());

    expect($icon->extension)->toBe('png')
        ->and(str_starts_with($icon->bytes, Png::SIGNATURE))->toBeTrue()
        ->and(Png::normalize($icon->bytes))->toMatchArray(['width' => 2, 'height' => 2]);
});

test('ICOs with an embedded PNG use the re-encoded PNG', function (): void {
    $png = tinyPng(iconChunk('tEXt', "x\x00secret"));
    $ico = Ico::SIGNATURE.pack('v', 1).pack('CCCCvvVV', 2, 2, 0, 0, 1, 32, strlen($png), 22).$png;

    $icon = IconSanitizer::sanitize($ico);

    expect($icon->bytes)->not->toContain('secret')->and($icon->extension)->toBe('png');
});

test('WebP containers are rebuilt without metadata', function (): void {
    $vp8l = "\x2F".pack('V', (1 - 1) | ((1 - 1) << 14))."\x00\x00\x00";
    $chunks = 'VP8L'.pack('V', strlen($vp8l)).$vp8l.(strlen($vp8l) % 2 ? "\x00" : '')
        .'EXIF'.pack('V', 8).'evil-gps';
    $webp = 'RIFF'.pack('V', 4 + strlen($chunks)).'WEBP'.$chunks.'trailing junk';

    $icon = IconSanitizer::sanitize($webp);

    expect($icon->extension)->toBe('webp')
        ->and($icon->bytes)->not->toContain('EXIF')
        ->and($icon->bytes)->not->toContain('trailing');

    $animated = 'RIFF'.pack('V', 4 + 8 + 6).'WEBP'.'ANIM'.pack('V', 6).str_repeat("\x00", 6);
    expect(fn (): array => Webp::normalize($animated))->toThrow(InvalidImage::class);
});

test('SVGs are rebuilt from an allow-list', function (): void {
    $svg = <<<'SVG'
        <?xml version="1.0"?>
        <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 16 16" onload="alert(1)">
          <script>alert(2)</script>
          <style>@import url(https://evil.test/x.css); .a{fill:url(https://evil.test/p)} .b{fill:#000}</style>
          <defs><linearGradient id="g"><stop offset="0" stop-color="#fff"/></linearGradient></defs>
          <foreignObject><iframe src="https://evil.test"/></foreignObject>
          <image href="https://evil.test/track.png"/>
          <a href="javascript:alert(3)"><circle r="2"/></a>
          <use xlink:href="https://evil.test/sprite.svg#x"/>
          <use href="#g"/>
          <rect width="16" height="16" fill="url(#g)" style="fill:url(https://evil.test/q)" onclick="alert(4)"/>
          <path d="M0 0h16" stroke="javascript:alert(5)"/>
        </svg>
        SVG;

    $out = IconSanitizer::sanitize($svg)->bytes;

    expect($out)->toContain('<svg xmlns="http://www.w3.org/2000/svg"')
        ->toContain('viewBox="0 0 16 16"')
        ->toContain('fill="url(#g)"')
        ->toContain('href="#g"')
        ->not->toContain('script')
        ->not->toContain('alert')
        ->not->toContain('onload')
        ->not->toContain('onclick')
        ->not->toContain('evil.test')
        ->not->toContain('foreignObject')
        ->not->toContain('<image')
        ->not->toContain('<a ')
        ->not->toContain('@import');
});

test('SVGs with a DOCTYPE or entities are refused (no XXE, no billion laughs)', function (): void {
    $xxe = '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>';

    expect(fn (): IconImage => IconSanitizer::sanitize($xxe))->toThrow(InvalidImage::class);
});

test('anything but PNG, ICO, SVG and WebP is refused', function (string $bytes): void {
    expect(fn (): IconImage => IconSanitizer::sanitize($bytes))->toThrow(InvalidImage::class);
})->with([
    'GIF' => "GIF89a\x01\x00\x01\x00\x00\x00\x00;",
    'JPEG' => "\xFF\xD8\xFF\xE0\x00\x10JFIF",
    'HTML' => '<html><script>alert(1)</script></html>',
    'empty' => '',
    'too big' => str_repeat('a', IconSanitizer::MAX_BYTES + 1),
]);
