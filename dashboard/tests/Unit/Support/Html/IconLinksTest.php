<?php

declare(strict_types=1);

use App\Support\Html\IconLinks;
use App\Support\Html\Urls;

test('icon links are found, resolved and ranked best first', function (): void {
    $html = <<<'HTML'
        <!doctype html><html><head>
          <link rel="shortcut icon" href="/favicon.ico">
          <link rel="icon" type="image/png" sizes="16x16" href="icons/16.png">
          <link rel="apple-touch-icon" href="//cdn.example.com/touch.png">
          <link rel="icon" href="/logo.svg" type="image/svg+xml">
          <link rel="mask-icon" href="/mask.svg">
          <link rel="icon" href="javascript:alert(1)">
          <link rel="stylesheet" href="/app.css">
        </head></html>
        HTML;

    expect(IconLinks::candidates($html, 'https://shop.example.com/store/'))->toBe([
        'https://shop.example.com/logo.svg',
        'https://cdn.example.com/touch.png',
        'https://shop.example.com/store/icons/16.png',
        'https://shop.example.com/favicon.ico',
    ]);
});

test('a base element changes where relative icons resolve', function (): void {
    $html = '<html><head><base href="https://static.example.com/assets/"><link rel="icon" href="../i.png"></head></html>';

    expect(IconLinks::candidates($html, 'https://shop.example.com/'))->toBe(['https://static.example.com/i.png']);
});

test('only http(s) and data:image URLs resolve', function (string $reference, ?string $expected): void {
    expect(Urls::resolve('https://a.example.com/x/y', $reference))->toBe($expected);
})->with([
    ['/a.png', 'https://a.example.com/a.png'],
    ['b.png?v=2#frag', 'https://a.example.com/x/b.png?v=2'],
    ['../../../c.png', 'https://a.example.com/c.png'],
    ['http://other.example.com/d.png', 'http://other.example.com/d.png'],
    ['data:image/png;base64,AAAA', 'data:image/png;base64,AAAA'],
    ['file:///etc/passwd', null],
    ['javascript:alert(1)', null],
    ['gopher://x/', null],
    ["/a\nb.png", null],
]);
