<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Outcome of one server health check (App\Queries\ServerHealth).
 */
enum HealthCheckState: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Critical = 'critical';
    /** The daemon has not reported this fact (yet), or the report is stale. */
    case Unknown = 'unknown';

    public function worst(self $other): self
    {
        $rank = [self::Ok->value => 0, self::Unknown->value => 1, self::Warning->value => 2, self::Critical->value => 3];

        return $rank[$other->value] > $rank[$this->value] ? $other : $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'OK',
            self::Warning => 'Warning',
            self::Critical => 'Failing',
            self::Unknown => 'Unknown',
        };
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Ok => StatusTone::Active,
            self::Warning => StatusTone::Warning,
            self::Critical => StatusTone::Failed,
            self::Unknown => StatusTone::Idle,
        };
    }
}
