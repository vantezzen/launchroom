<?php

declare(strict_types=1);

namespace App\Data\Transfers;

use App\Services\Transfers\DatabasePreparations;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * A database that was never deployed, for an import before its first deploy
 * ({@see DatabasePreparations}, docs/14 §12.9).
 */
final class DatabasePreparationData extends Data
{
    public function __construct(
        /** needed: not started · starting · ready: running alone for the import · failed: it didn't start */
        #[LiteralTypeScriptType("'needed' | 'starting' | 'ready' | 'failed'")]
        public string $state,
        /** The server it runs on once placed. */
        public ?string $serverName,
        /** Why it didn't start (state failed). */
        public ?string $message,
    ) {}
}
