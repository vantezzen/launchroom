<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Data\Attributes\PublicIdOf;
use App\Data\Protocol\ProtocolDocument;
use App\Support\Protocol\Grammar;
use App\Support\Protocol\Limits;
use App\Support\PublicId;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * POST /daemon/v1/reports (protocol/schema/v1/report.json).
 */
final class Report extends Data
{
    /** RFC 3339 as Go emits it (time.RFC3339Nano): up to 9 fraction digits, `Z` or an offset. */
    public const string TIME_PATTERN = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,9})?(?:Z|[+-][0-9]{2}:[0-9]{2})$/D';

    /**
     * @param  list<WorkloadStatus>  $workloads
     * @param  list<VolumeDeletionResult>|null|Optional  $volumeDeletions
     * @param  array<string, mixed>|null|Optional  $metrics
     * @param  list<WorkloadEvent>|null|Optional  $events
     */
    public function __construct(
        #[In([ProtocolDocument::API_VERSION])]
        public string $apiVersion,
        #[In(['Report'])]
        public string $kind,
        #[PublicIdOf('srv')]
        public string $serverId,
        #[Regex(self::TIME_PATTERN)]
        public string $observedAt,
        #[Min(1)]
        public int $seq,
        public bool $full,
        #[Min(0)]
        public int $appliedVersion,
        public array $workloads,
        public HostStatus|null|Optional $host = new Optional,
        public StateRejection|null|Optional $stateRejected = new Optional,
        public array|null|Optional $volumeDeletions = new Optional,
        public IngressStatus|null|Optional $ingress = new Optional,
        /** Per-minute usage samples (normalized by App\Support\Metrics\MetricSamples, invalid items are dropped). */
        public array|null|Optional $metrics = new Optional,
        /**
         * WAL shipping of point-in-time recovery (docs/14 §10.2), recorded by App\Actions\Backups\RecordWalArchives.
         *
         * @var list<array<string, mixed>>|null|Optional
         */
        public array|null|Optional $walArchives = new Optional,
        /** Container lifecycle events (D68), recorded by App\Actions\Servers\RecordWorkloadEvents. */
        public array|null|Optional $events = new Optional,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        // Present even when empty (protocol/README.md: required arrays are always present).
        return [
            'workloads' => ['present', 'array', 'max:'.Limits::LIST_ITEMS],
            // Items are checked one by one by MetricSamples (cheap for thousands of rows).
            'metrics' => ['nullable', 'array'],
            'metrics.host' => ['sometimes', 'array', 'max:120'],
            'metrics.workloads' => ['sometimes', 'array', 'max:8192'],
            // Request metrics of edges (docs/15 §1.2).
            'metrics.requests' => ['sometimes', 'array', 'max:8192'],
            // Request analytics of edges (docs/15 §5.2).
            'metrics.analytics' => ['sometimes', 'array', 'max:1024'],
            // Web firewall samples of edges (docs/03 §13.4), checked by App\Support\Security\WafSamples.
            'metrics.waf' => ['sometimes', 'array', 'max:1024'],
            'walArchives' => ['sometimes', 'nullable', 'array', 'max:256'],
            'events' => ['sometimes', 'nullable', 'array', 'max:256'],
            'walArchives.*.workload' => ['required', 'string', 'regex:'.PublicId::regex('wl')],
            'walArchives.*.state' => ['required', 'string', 'in:active,waiting,failing'],
            'walArchives.*.checkedAt' => ['required', 'string', 'regex:'.self::TIME_PATTERN],
            'walArchives.*.lastArchivedAt' => ['sometimes', 'nullable', 'string', 'regex:'.self::TIME_PATTERN],
            'walArchives.*.lastSegment' => ['sometimes', 'string', 'regex:'.Grammar::WAL_SEGMENT],
            'walArchives.*.pendingSegments' => ['required', 'integer', 'min:0'],
            'walArchives.*.message' => ['sometimes', 'string', 'max:1024'],
        ];
    }
}
