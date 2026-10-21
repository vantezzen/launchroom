<?php

declare(strict_types=1);

namespace App\Http\Daemon;

use App\Data\Protocol\ProtocolDocument;
use App\Data\Protocol\ProtocolJson;
use Illuminate\Http\Response;

/**
 * A protocol document as an HTTP response. The body is encoded exactly once;
 * SignResponse signs these bytes.
 */
final class ProtocolResponse
{
    public static function make(ProtocolDocument $document, int $status = 200): Response
    {
        return self::raw(ProtocolJson::encode($document), $status);
    }

    public static function raw(string $json, int $status = 200): Response
    {
        return new Response($json, $status, ['Content-Type' => 'application/json']);
    }
}
