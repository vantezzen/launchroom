<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * Report `events[]` (report.json WorkloadEvent, D68): one container
 * lifecycle event. Stored once per (server, seq) as a deployment log line
 * (App\Actions\Servers\RecordWorkloadEvents); the wording is the
 * dashboard's (App\Support\Deployments\LifecycleLines).
 */
final class WorkloadEvent extends Data
{
    public const array KINDS = ['created', 'create_failed', 'started', 'exited', 'restarted', 'health_passing', 'health_failing', 'stopped', 'removed'];

    public const array REASONS = ['drained', 'not_desired', 'spec_changed', 'duplicate', 'port_not_bound'];

    /**
     * @param  list<LogLine>|null|Optional  $output
     */
    public function __construct(
        #[Min(1)]
        public int $seq,
        #[Regex('/^wl_[0-9A-HJKMNP-TV-Z]{26}$/D')]
        public string $workload,
        #[Regex(Report::TIME_PATTERN)]
        public string $at,
        #[In(self::KINDS)]
        public string $kind,
        #[Regex('/^dpl_[0-9A-HJKMNP-TV-Z]{26}$/D')]
        public string|Optional $deployment = new Optional,
        #[In(self::REASONS)]
        public string|Optional $reason = new Optional,
        public int|null|Optional $exitCode = new Optional,
        public bool|Optional $oomKilled = new Optional,
        #[Min(0)]
        public int|Optional $restarts = new Optional,
        #[Min(0)]
        public int|Optional $failures = new Optional,
        #[Max(1024)]
        public string|Optional $message = new Optional,
        public array|null|Optional $output = new Optional,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return ['output' => ['nullable', 'array', 'max:30'], 'output.*.text' => ['string', 'max:2048']];
    }

    /**
     * The output lines (already validated).
     *
     * @return list<LogLine>
     */
    public function lines(): array
    {
        return is_array($this->output) ? $this->output : [];
    }
}
