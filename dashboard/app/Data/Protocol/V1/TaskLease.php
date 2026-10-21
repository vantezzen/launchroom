<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Data\Protocol\ProtocolDocument;
use Spatie\LaravelData\Data;

/**
 * Signed response to a lease request (protocol/schema/v1/task-lease.json).
 */
final class TaskLease extends Data implements ProtocolDocument
{
    /**
     * @param  list<Task>  $tasks
     */
    public function __construct(
        public array $tasks,
    ) {}

    public function toProtocol(): array
    {
        return [
            'apiVersion' => self::API_VERSION,
            'kind' => 'TaskLease',
            'tasks' => array_map(static fn (Task $task): array => $task->toProtocol(), $this->tasks),
        ];
    }
}
