<?php

declare(strict_types=1);

namespace App\Support\Security;

/**
 * The note of "Not affected…" (docs/22 §5.5): control characters removed,
 * whitespace collapsed to single spaces, at most 500 characters; empty is
 * null. Pure.
 */
final class DismissNote
{
    public static function clean(?string $note): ?string
    {
        if ($note === null) {
            return null;
        }

        $clean = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1f\x7f]+/u', '', $note)));

        return $clean === '' ? null : mb_substr($clean, 0, 500);
    }
}
