<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Data\Attributes\PublicIdOf;
use App\Data\Attributes\UnknownCodeAsNull;
use App\Enums\WorkloadEventKind;
use App\Enums\WorkloadEventReason;
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
    /**
     * @param  list<LogLine>|null|Optional  $output
     */
    public function __construct(
        #[Min(1)]
        public int $seq,
        #[PublicIdOf('wl')]
        public string $workload,
        #[Regex(Report::TIME_PATTERN)]
        public string $at,
        public WorkloadEventKind $kind,
        #[PublicIdOf('dpl')]
        public string|Optional $deployment = new Optional,
        #[UnknownCodeAsNull]
        public WorkloadEventReason|null|Optional $reason = new Optional,
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
     * @return list<LogLine>
     */
    public function lines(): array
    {
        return is_array($this->output) ? $this->output : [];
    }
}
