<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Data;

final class PollIntervals extends Data
{
    public function __construct(
        public string $state,
        public string $tasks,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toProtocol(): array
    {
        return ['state' => $this->state, 'tasks' => $this->tasks];
    }
}
