<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\In;
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
        #[Regex('/^wl_[0-9A-HJKMNP-TV-Z]{26}$/D')]
        public string $id,
        #[In(['pending', 'pulling', 'starting', 'running', 'healthy', 'unhealthy', 'draining', 'exited', 'failed', 'removed'])]
        public string $phase,
        #[Regex(Report::TIME_PATTERN)]
        public string $observedAt,
        #[Min(0)]
        public int $restartCount,
        #[Regex('/^[a-f0-9]{64}$/D')]
        public string|Optional $containerId = new Optional,
        #[Regex(Report::TIME_PATTERN)]
        public string|null|Optional $startedAt = new Optional,
        public int|null|Optional $exitCode = new Optional,
        #[Max(4096)]
        public string|Optional $message = new Optional,
        public array|null|Optional $detectedPorts = new Optional,
        public ProbeStatus|null|Optional $health = new Optional,
        public PullProgress|null|Optional $pull = new Optional,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return ['detectedPorts.*' => ['integer', 'min:1', 'max:65535']];
    }
}
