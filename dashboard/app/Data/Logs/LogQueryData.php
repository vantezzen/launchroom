<?php

declare(strict_types=1);

namespace App\Data\Logs;

use App\Data\Attributes\PublicIdOf;
use App\Enums\LogRange;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * Query of the log explorer endpoints: `range`, `source` (all; runtime: everything but build output; build: the plan,
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
    public function __construct(
        public LogRange $range = LogRange::Hour,
        #[In(['all', 'runtime', 'build'])]
        public string $source = 'all',
        #[PublicIdOf('svc')]
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
        return $this->range->seconds();
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

        $oldest = $now->subSeconds(LogRange::Month->seconds())->getTimestamp();
        $to = min(max($this->to, $oldest + 60), $now->getTimestamp());
        $from = min(max($this->from, $oldest), $to - 60);

        return [CarbonImmutable::createFromTimestampUTC($from), CarbonImmutable::createFromTimestampUTC($to)];
    }

    public function absolute(): bool
    {
        return $this->from !== null && $this->to !== null;
    }
}
