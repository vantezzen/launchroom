<?php

declare(strict_types=1);

namespace App\Data\Backups;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * A restic forget policy (docs/14 §3; protocol `Retention`). Stored as
 * `backup_schedules.retention`.
 */
final class RetentionData extends Data
{
    public function __construct(
        #[Min(0), Max(1000)]
        public int $keepLast = 0,
        #[Min(0), Max(1000)]
        public int $keepHourly = 0,
        #[Min(0), Max(1000)]
        public int $keepDaily = 0,
        #[Min(0), Max(1000)]
        public int $keepWeekly = 0,
        #[Min(0), Max(1000)]
        public int $keepMonthly = 0,
        /** Keep everything this many hours back from the newest (point-in-time recovery, docs/14 §10.5). */
        #[Min(0), Max(87600)]
        public int $keepWithinHours = 0,
    ) {}

    /**
     * Base backups and WAL of point-in-time recovery: everything within the
     * window plus a day (so the oldest point stays reachable from the base
     * backup before it), and always the newest snapshot.
     */
    public static function pointInTime(int $keepDays): self
    {
        return new self(keepLast: 1, keepWithinHours: ($keepDays + 1) * 24);
    }

    /**
     * docs/14 §3 defaults.
     */
    public static function controlPlane(): self
    {
        return new self(keepDaily: 7, keepWeekly: 4, keepMonthly: 6);
    }

    public static function production(): self
    {
        return new self(keepDaily: 7, keepWeekly: 4, keepMonthly: 3);
    }

    public static function persistent(): self
    {
        return new self(keepDaily: 7);
    }

    /**
     * Volumes (any persistent environment): 7 daily, 4 weekly.
     */
    public static function volume(): self
    {
        return new self(keepDaily: 7, keepWeekly: 4);
    }

    public function keepsSomething(): bool
    {
        return $this->keepLast + $this->keepHourly + $this->keepDaily + $this->keepWeekly + $this->keepMonthly + $this->keepWithinHours > 0;
    }

    /**
     * The protocol object (only non-zero fields; the daemon rejects an empty policy).
     *
     * @return array<string, int>
     */
    public function toProtocol(): array
    {
        return array_filter([
            'keepLast' => $this->keepLast,
            'keepHourly' => $this->keepHourly,
            'keepDaily' => $this->keepDaily,
            'keepWeekly' => $this->keepWeekly,
            'keepMonthly' => $this->keepMonthly,
            'keepWithinHours' => $this->keepWithinHours,
        ], static fn (int $value): bool => $value > 0);
    }

    /**
     * restic forget flags.
     *
     * @return list<string>
     */
    public function resticFlags(): array
    {
        $flags = [];

        foreach ($this->toProtocol() as $field => $count) {
            if ($field === 'keepWithinHours') {
                $flags[] = '--keep-within';
                $flags[] = $count.'h';

                continue;
            }

            $flags[] = '--'.strtolower((string) preg_replace('/([A-Z])/', '-$1', $field));
            $flags[] = (string) $count;
        }

        return $flags;
    }

    public function summary(): string
    {
        $labels = ['keepLast' => 'last', 'keepHourly' => 'hourly', 'keepDaily' => 'daily', 'keepWeekly' => 'weekly', 'keepMonthly' => 'monthly'];
        $parts = [];

        if ($this->keepWithinHours > 0) {
            $parts[] = 'everything from the last '.intdiv($this->keepWithinHours, 24).' days';
        }

        foreach (array_diff_key($this->toProtocol(), ['keepWithinHours' => true]) as $field => $count) {
            $parts[] = $count.' '.$labels[$field];
        }

        return $parts === [] ? 'Keep nothing' : implode(', ', $parts);
    }
}
