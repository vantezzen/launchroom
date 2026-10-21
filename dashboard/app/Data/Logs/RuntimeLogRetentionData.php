<?php

declare(strict_types=1);

namespace App\Data\Logs;

use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

/**
 * PATCH /p/{project}/{environment}/log-retention: keep this environment's
 * runtime logs (opt-in) for `days`, up to `maxMb` (options from
 * `launchroom.logs`). Also the settings page's props.
 */
final class RuntimeLogRetentionData extends Data
{
    public function __construct(
        public bool $enabled,
        public int $days,
        public int $maxMb,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'days' => ['required', 'integer', Rule::in(self::dayOptions())],
            'maxMb' => ['required', 'integer', Rule::in(self::sizeOptions())],
        ];
    }

    /**
     * @return list<int>
     */
    public static function dayOptions(): array
    {
        /** @var list<int> */
        return config()->array('launchroom.logs.retention_days');
    }

    /**
     * @return list<int>
     */
    public static function sizeOptions(): array
    {
        /** @var list<int> */
        return config()->array('launchroom.logs.max_mb');
    }
}
