<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Data\Protocol\ProtocolJson;
use App\Data\Protocol\V1\Tasks\TaskSpec;
use App\Enums\DaemonTaskType;
use App\Models\DaemonTask;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * One leased task (protocol/schema/v1/task.json).
 */
final class Task extends Data
{
    public function __construct(
        public string $id,
        public DaemonTaskType $type,
        public CarbonImmutable $leaseExpiresAt,
        public int $attempt,
        public CarbonImmutable $deadline,
        public TaskSpec $spec,
    ) {}

    /**
     * @param  TaskSpec  $spec  the spec as materialized for the leasing server (secrets sealed)
     */
    public static function fromTask(DaemonTask $task, TaskSpec $spec): self
    {
        return new self(
            $task->public_id,
            $task->type,
            ($task->lease_expires_at ?? now())->toImmutable(),
            $task->attempt,
            ($task->deadline_at ?? now())->toImmutable(),
            $spec,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toProtocol(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'leaseExpiresAt' => ProtocolJson::time($this->leaseExpiresAt),
            'attempt' => $this->attempt,
            'deadline' => ProtocolJson::time($this->deadline),
            'spec' => $this->spec->toProtocol(),
        ];
    }
}
