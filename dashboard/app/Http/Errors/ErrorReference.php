<?php

declare(strict_types=1);

namespace App\Http\Errors;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The id a 5xx page shows and its log entry carries (docs/08 §24): the
 * request's `X-Request-Id` when the edge set a well-formed one, otherwise a
 * new UUID, the same for the whole request.
 */
final class ErrorReference
{
    private const string ATTRIBUTE = 'launchroom.error_reference';

    public static function for(Request $request): string
    {
        $known = $request->attributes->get(self::ATTRIBUTE);

        if (is_string($known)) {
            return $known;
        }

        $incoming = $request->headers->get('X-Request-Id');
        $reference = is_string($incoming) && Str::isUuid($incoming) ? strtolower($incoming) : (string) Str::uuid();
        $request->attributes->set(self::ATTRIBUTE, $reference);

        return $reference;
    }
}
