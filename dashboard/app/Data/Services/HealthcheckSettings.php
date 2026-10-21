<?php

declare(strict_types=1);

namespace App\Data\Services;

use App\Enums\HealthcheckType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * The daemon's external probe (docs/04 §8). Durations in whole seconds.
 */
final class HealthcheckSettings extends Data
{
    /** Same grammar as the NodeState HealthSpec path. */
    public const string PATH_PATTERN = "/^\\/[A-Za-z0-9._~!$&'()*+,;=:@%\\/?-]*$/D";

    public function __construct(
        public HealthcheckType $type = HealthcheckType::Http,
        #[Regex(self::PATH_PATTERN), Max(1024)]
        public string $path = '/',
        #[Regex('/^[1-5](?:xx|[0-9]{2})(?:-[1-5](?:xx|[0-9]{2}))?$/D')]
        public string $expectStatus = '2xx-3xx',
        #[Min(1), Max(3600)]
        public int $startPeriodSeconds = 30,
        #[Min(1), Max(3600)]
        public int $intervalSeconds = 5,
        #[Min(1), Max(600)]
        public int $timeoutSeconds = 3,
        #[Min(1), Max(100)]
        public int $failureThreshold = 3,
    ) {}

    public static function tcp(int $startPeriodSeconds = 30, int $intervalSeconds = 5, int $failureThreshold = 3): self
    {
        return new self(HealthcheckType::Tcp, startPeriodSeconds: $startPeriodSeconds, intervalSeconds: $intervalSeconds, failureThreshold: $failureThreshold);
    }

    public static function none(): self
    {
        return new self(HealthcheckType::None);
    }

    /**
     * Upper bound for a new workload to become healthy, after which the
     * deployment fails with `health_timeout`.
     */
    public function deadlineSeconds(): int
    {
        return $this->startPeriodSeconds + ($this->intervalSeconds + $this->timeoutSeconds) * $this->failureThreshold + 120;
    }
}
