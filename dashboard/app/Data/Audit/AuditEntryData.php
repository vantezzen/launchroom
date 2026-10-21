<?php

declare(strict_types=1);

namespace App\Data\Audit;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use Spatie\LaravelData\Data;

/**
 * Input for {@see RecordAuditEvent}. `meta` must never
 * contain secret values (docs/09 R16): ids, roles, names, fingerprints only.
 */
final class AuditEntryData extends Data
{
    /**
     * @param  array<string, string|int|bool|null|list<string>>  $meta
     */
    public function __construct(
        public AuditAction $action,
        public AuditActor $actor,
        public ?int $teamId = null,
        public ?string $subjectType = null,
        public ?string $subjectId = null,
        public array $meta = [],
    ) {}
}
