<?php

declare(strict_types=1);

namespace App\Data\Sessions;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * The Data tab of a managed database (docs/08 §15).
 */
final class DataTabData extends Data
{
    /**
     * @param  list<QueryHistoryItemData>  $history
     */
    public function __construct(
        public bool $available,
        public ?string $unavailableReason,
        /** DatabaseEngine value (`postgres`, `mysql`, `redis`, `valkey`, `mongodb`, `clickhouse`). */
        public string $engine,
        public string $engineLabel,
        /**
         * How it is browsed (DatabaseEngine::dataDialect()): `sql` tables and a
         * SQL console; `redis` keys and commands; `mongo` collections and
         * database commands as JSON documents.
         */
        #[LiteralTypeScriptType("'sql' | 'redis' | 'mongo'")]
        public string $dialect,
        public ?SessionReplicaData $replica,
        public int $idleMinutes,
        public array $history,
    ) {}
}
