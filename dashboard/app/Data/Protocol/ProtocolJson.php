<?php

declare(strict_types=1);

namespace App\Data\Protocol;

use Carbon\CarbonInterface;
use JsonException;

/**
 * Encodes protocol documents. The returned string is the exact body that is
 * signed and sent (protocol/SIGNING.md §3): never re-encode it.
 */
final class ProtocolJson
{
    /**
     * @throws JsonException
     */
    public static function encode(ProtocolDocument $document): string
    {
        return json_encode($document->toProtocol(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * RFC 3339 in UTC with whole seconds: `2026-10-17T12:00:00Z`.
     */
    public static function time(CarbonInterface $time): string
    {
        return $time->toImmutable()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
