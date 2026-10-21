<?php

declare(strict_types=1);

namespace App\Http\Daemon;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use JsonException;
use Spatie\LaravelData\Data;

/**
 * Decodes and validates a daemon request body into a protocol Data object.
 * The body must be exactly one JSON object; anything else is a 422.
 */
final class ProtocolPayload
{
    /**
     * @template T of Data
     *
     * @param  class-string<T>  $class
     * @return T
     */
    public static function decode(Request $request, string $class): Data
    {
        try {
            $payload = json_decode($request->getContent(), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages(['body' => 'The body must be a JSON document.']);
        }

        if (! is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            throw ValidationException::withMessages(['body' => 'The body must be a JSON object.']);
        }

        return $class::validateAndCreate($payload);
    }
}
