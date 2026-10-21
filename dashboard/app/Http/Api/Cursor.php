<?php

declare(strict_types=1);

namespace App\Http\Api;

use Illuminate\Validation\ValidationException;

/**
 * Opaque list cursors (docs/16 §2.1): the last id of the page, base64url
 * encoded. Ids are internal and only ever compared within an already
 * team-scoped query, so a forged cursor can't reach other rows.
 */
final class Cursor
{
    public static function encode(int $lastId): string
    {
        return rtrim(strtr(base64_encode('v1:'.$lastId), '+/', '-_'), '=');
    }

    public static function decode(?string $cursor): ?int
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);

        if (! is_string($raw) || preg_match('/^v1:([1-9][0-9]{0,18})$/D', $raw, $match) !== 1) {
            throw ValidationException::withMessages(['cursor' => ['This cursor is not valid.']]);
        }

        return (int) $match[1];
    }
}
