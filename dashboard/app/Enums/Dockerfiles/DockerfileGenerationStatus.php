<?php

declare(strict_types=1);

namespace App\Enums\Dockerfiles;

use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;

/**
 * Lifecycle of a Dockerfile generation (docs/20 §5): read the repository,
 * draft, build, start; a failed build or start goes back to drafting for a
 * repair while attempts remain.
 */
enum DockerfileGenerationStatus: string implements HasTransitionTable
{
    use HasTransitions;

    case Reading = 'reading';
    case Drafting = 'drafting';
    case Building = 'building';
    case Starting = 'starting';
    case Verified = 'verified';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Reading => [self::Drafting, self::Failed, self::Cancelled],
            self::Drafting => [self::Drafting, self::Building, self::Failed, self::Cancelled],
            self::Building => [self::Starting, self::Drafting, self::Failed, self::Cancelled],
            self::Starting => [self::Verified, self::Drafting, self::Failed, self::Cancelled],
            self::Verified, self::Failed, self::Cancelled => [],
        };
    }

    public function isRunning(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * @return list<string>
     */
    public static function runningValues(): array
    {
        return [self::Reading->value, self::Drafting->value, self::Building->value, self::Starting->value];
    }
}
