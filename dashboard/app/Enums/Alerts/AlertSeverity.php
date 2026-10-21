<?php

declare(strict_types=1);

namespace App\Enums\Alerts;

/**
 * How urgent an alert is (docs/15 §3). Channels receive alerts at or above
 * their minimum severity.
 */
enum AlertSeverity: string
{
    case Warning = 'warning';
    case Critical = 'critical';

    public function rank(): int
    {
        return match ($this) {
            self::Warning => 1,
            self::Critical => 2,
        };
    }

    public function atLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    public function label(): string
    {
        return match ($this) {
            self::Warning => 'Warning',
            self::Critical => 'Critical',
        };
    }
}
