<?php

declare(strict_types=1);

namespace App\Data\Logs;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * Query of the log explorer endpoints: `range` (15m, 1h, 24h, 7d, 30d),
 * `source` (all; runtime: everything but build output; build: the plan,
 * build and release output of deployments), an optional `service`, the search `q`
 * (App\Support\Logs\LogSearchQuery) and the live cursor `after` (the last
 * chunk id seen).
 *
 * `from` and `to` (epoch seconds; one alone is ignored) ask for an absolute
 * window instead of the last `range`, so a chart's bucket or an alert can
 * open the logs of that moment (docs/08 §12). The window is clamped to the
 * last 30 days (the longest retention) and to now; a window shorter than a
 * minute is widened to one.
 */
final class LogQueryData extends Data
{
    public const array RANGES = ['15m' => 900, '1h' => 3600, '24h' => 86400, '7d' => 604800, '30d' => 2592000];

    public function __construct(
        #[In(['15m', '1h', '24h', '7d', '30d'])]
        public string $range = '1h',
        #[In(['all', 'runtime', 'build'])]
        public string $source = 'all',
        #[Regex('/^svc_[0-7][0-9A-HJKMNP-TV-Z]{25}$/D')]
        public ?string $service = null,
        #[Min(0)]
        public int $after = 0,
        #[Max(512)]
        public ?string $q = null,
        #[Min(0)]
        public ?int $from = null,
        #[Min(0)]
        public ?int $to = null,
    ) {}

    public function seconds(): int
    {
        return self::RANGES[$this->range] ?? 3600;
    }

    /**
     * The searched window: the absolute one when given (clamped), else the
     * last `range` before now.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public function window(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        if ($this->from === null || $this->to === null) {
            return [$now->subSeconds($this->seconds()), $now];
        }

        $oldest = $now->subSeconds(self::RANGES['30d'])->getTimestamp();
        $to = min(max($this->to, $oldest + 60), $now->getTimestamp());
        $from = min(max($this->from, $oldest), $to - 60);

        return [CarbonImmutable::createFromTimestampUTC($from), CarbonImmutable::createFromTimestampUTC($to)];
    }

    /** Whether this asks for an absolute window rather than the last `range`. */
    public function absolute(): bool
    {
        return $this->from !== null && $this->to !== null;
    }
}
