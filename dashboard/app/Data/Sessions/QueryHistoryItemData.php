<?php

declare(strict_types=1);

namespace App\Data\Sessions;

use App\Enums\Sessions\DatabaseQueryStatus;
use Spatie\LaravelData\Data;

/**
 * One statement in the user's own query history.
 */
final class QueryHistoryItemData extends Data
{
    public function __construct(
        public string $id,
        /** The statement text, or null when it can no longer be decrypted (pruned key). */
        public ?string $statement,
        public DatabaseQueryStatus $status,
        public bool $allowWrites,
        public bool $truncated,
        public ?int $rows,
        public ?int $durationMs,
        public string $createdAt,
    ) {}
}
