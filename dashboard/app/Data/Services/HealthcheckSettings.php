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
        /**
         * Restart a running replica whose check keeps failing for this long
         * (at most 3 times an hour, docs/04 §8); 0: never; null: 5 minutes.
         */
        #[Min(0), Max(3600)]
        public ?int $restartAfterSeconds = null,
    ) {}

    public const int RESTART_AFTER_DEFAULT = 300;

    /**
     * Seconds of failing checks before launchroomd restarts the replica;
     * null: never. Values under a minute count as one minute (the protocol's
     * lower bound).
     */
    public function restartAfter(): ?int
    {
        $seconds = $this->restartAfterSeconds ?? self::RESTART_AFTER_DEFAULT;

        return $this->type === HealthcheckType::None || $seconds === 0 ? null : max(60, $seconds);
    }

    /**
     * The path is the implicit `/` with the default expectation: any
     * answer below 500 counts as up (OD3), since an app without a root
     * route still serves. Explicit and preset paths keep 2xx-3xx.
     */
    public function implicitPath(): bool
    {
        return $this->type === HealthcheckType::Http && $this->path === '/' && $this->expectStatus === '2xx-3xx';
    }

    public static function tcp(int $startPeriodSeconds = 30, int $intervalSeconds = 5, int $failureThreshold = 3): self
    {
        return new self(HealthcheckType::Tcp, startPeriodSeconds: $startPeriodSeconds, intervalSeconds: $intervalSeconds, failureThreshold: $failureThreshold);
    }

    public static function none(): self
    {
        return new self(HealthcheckType::None);
    }
}
