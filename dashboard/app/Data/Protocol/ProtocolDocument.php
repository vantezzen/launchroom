<?php

declare(strict_types=1);

namespace App\Data\Protocol;

use App\Support\Protocol\Grammar;

/**
 * A dashboard → daemon document (protocol/README.md). `toProtocol()` returns
 * exactly the wire shape: camelCase fields, optional fields omitted, maps as
 * objects (so empty maps encode as `{}`, never `[]`).
 */
interface ProtocolDocument
{
    public const string API_VERSION = Grammar::API_VERSION;

    /**
     * @return array<string, mixed>
     */
    public function toProtocol(): array;
}
