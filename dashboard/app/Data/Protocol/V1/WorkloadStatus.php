<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Data\Attributes\PublicIdOf;
use App\Data\Attributes\UnknownCodeAsNull;
use App\Enums\PortHint;
use App\Enums\WorkloadPhase;
use App\Support\Protocol\Grammar;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

final class WorkloadStatus extends Data
{
    /**
     * @param  list<int>|null|Optional  $detectedPorts
     */
    public function __construct(
        #[PublicIdOf('wl')]
        public string $id,
        public WorkloadPhase $phase,
        #[Regex(Report::TIME_PATTERN)]
        public string $observedAt,
        #[Min(0)]
        public int $restartCount,
        #[Regex(Grammar::HEX64)]
        public string|Optional $containerId = new Optional,
        #[Regex(Report::TIME_PATTERN)]
        public string|null|Optional $startedAt = new Optional,
        public int|null|Optional $exitCode = new Optional,
        #[Max(4096)]
        public string|Optional $message = new Optional,
        public array|null|Optional $detectedPorts = new Optional,
        public ProbeStatus|null|Optional $health = new Optional,
        public PullProgress|null|Optional $pull = new Optional,
        /** Clearly misconfigured (D68): fails an in-flight deployment right away. */
        public WorkloadIssue|null|Optional $issue = new Optional,
        /** Why the probe's port gets no answer (launchroomd's port hint); omitted when none. */
        #[UnknownCodeAsNull]
        public PortHint|null|Optional $portHint = new Optional,
        /** Why the container can't be pulled, created or started right now; omitted once it runs. */
        public WorkloadFailure|null|Optional $failure = new Optional,
        /** The image's user and exposed ports, once pulled. */
        public ImageFacts|null|Optional $image = new Optional,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return ['detectedPorts' => ['sometimes', 'nullable', 'array', 'max:32'], 'detectedPorts.*' => ['integer', 'min:1', 'max:65535']];
    }
}
