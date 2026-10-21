<?php

declare(strict_types=1);

namespace App\Data\Services;

use App\Support\Cron\CronGrammar;
use Closure;
use Spatie\LaravelData\Data;

/**
 * When a cron service runs (`DeploySettings.schedule`): a 5-field cron
 * expression read in an IANA time zone. launchroomd starts the job's
 * container at every due minute (`WorkloadSpec.schedule`, docs/04 §1).
 */
final class ScheduleSettings extends Data
{
    public function __construct(
        public string $cron,
        public string $timeZone = 'UTC',
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'cron' => ['required', 'string', 'max:'.CronGrammar::MAX_LENGTH, static function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && ($problem = CronGrammar::error($value)) !== null) {
                    $fail($problem);
                }
            }],
            'timeZone' => ['required', 'string', 'max:64', static function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && ($problem = CronGrammar::timeZoneError($value)) !== null) {
                    $fail($problem);
                }
            }],
        ];
    }
}
