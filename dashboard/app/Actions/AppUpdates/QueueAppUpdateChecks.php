<?php

declare(strict_types=1);

namespace App\Actions\AppUpdates;

use App\Enums\EnvironmentKind;
use App\Enums\ServiceKind;
use App\Jobs\AppUpdates\CheckAppUpdatesJob;
use App\Models\ServiceInstance;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Queues the version checks that are due (docs/04 §14.1): running image
 * services of lasting environments never checked, or whose next check has
 * come. At most $limit per run, oldest first; the rest follow next time.
 */
final readonly class QueueAppUpdateChecks
{
    public function handle(int $limit = 200, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();

        $ids = ServiceInstance::query()
            ->whereNull('removed_at')
            ->whereNull('stopped_at')
            ->whereNotNull('active_deployment_id')
            ->whereHas('service', static fn (Builder $query) => $query->where('kind', ServiceKind::Image->value))
            ->whereHas('environment', static fn (Builder $query) => $query->where('kind', '!=', EnvironmentKind::Preview->value))
            ->where(static fn (Builder $query) => $query
                ->whereDoesntHave('updateCheck')
                ->orWhereHas('updateCheck', static fn (Builder $check) => $check->where('next_check_at', '<=', $now)->orWhereNull('next_check_at')))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->all();

        /** @var list<int> $ids */
        foreach ($ids as $id) {
            dispatch(new CheckAppUpdatesJob($id));
        }

        return count($ids);
    }
}
