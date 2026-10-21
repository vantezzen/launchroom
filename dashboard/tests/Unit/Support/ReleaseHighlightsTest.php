<?php

declare(strict_types=1);

use App\Support\Updates\ReleaseHighlights;

test('section headings become plain-text highlights', function (): void {
    $notes = <<<'MD'
        ## What's Changed
        ## **Server map** on the servers page
        Some prose.
        ### Faster [builds](https://example.com) with `cache`
        ## <b>Bold</b> recovery kit
        ## Full Changelog
        MD;

    expect(ReleaseHighlights::from($notes))->toBe([
        'Server map on the servers page',
        'Faster builds with cache',
        'Bold recovery kit',
    ]);
});

test('without sub-headings the first bullets are used, at most five', function (): void {
    $notes = "- one by @mara in https://github.com/x/y/pull/1\n* two\n- three\n- four\n- five\n- six";

    expect(ReleaseHighlights::from($notes))->toBe(['one', 'two', 'three', 'four', 'five']);
});

test('empty notes have no highlights', function (): void {
    expect(ReleaseHighlights::from(''))->toBe([]);
});
