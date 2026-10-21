<?php

declare(strict_types=1);

namespace App\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Spatie\LaravelData\Data;

/**
 * The API's response envelopes (docs/16 §2.1): `{"data": {...}}` and
 * `{"data": [...], "meta": {"nextCursor": ...}}`. Never cached.
 */
final class ApiResponse
{
    public static function item(Data $data, int $status = 200): JsonResponse
    {
        return self::json(['data' => $data->toArray()], $status);
    }

    /**
     * @param  iterable<Data>  $items
     * @param  array<string, mixed>  $meta
     */
    public static function list(iterable $items, ?string $nextCursor = null, array $meta = []): JsonResponse
    {
        $data = [];

        foreach ($items as $item) {
            $data[] = $item->toArray();
        }

        return self::json(['data' => $data, 'meta' => ['nextCursor' => $nextCursor, ...$meta]]);
    }

    public static function noContent(): Response
    {
        return new Response('', 204, ['Cache-Control' => 'no-store']);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private static function json(array $body, int $status = 200): JsonResponse
    {
        return new JsonResponse($body, $status, ['Cache-Control' => 'no-store'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
