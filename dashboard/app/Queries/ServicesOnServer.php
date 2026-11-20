<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Moves\MoveBlockerData;
use App\Enums\WorkloadRole;
use App\Models\Placement;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Volume;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * What keeps a server from being retired (docs/14 §5.3, §13.2, §13.4): a
 * current service with a serving workload on it, or a volume there. One
 * rule for RetireServer, the hand-over's checks and the move pages.
 * Leftover rows of workloads launchroomd already removed (phase `removed`)
 * never count: their containers are gone.
 */
final class ServicesOnServer
{
    /**
     * @return list<MoveBlockerData> each service once, a volume before a workload
     */
    public static function blocking(Server $server, int $limit = 50): array
    {
        $current = static fn (Builder $query): Builder => $query->whereNull('removed_at');

        /** @var list<int> $withData */
        $withData = Volume::query()->where('server_id', $server->id)->whereHas('serviceInstance', $current)->distinct()->pluck('service_instance_id')->all();
        /** @var list<int> $running */
        $running = Placement::query()->present()->where('server_id', $server->id)->where('role', WorkloadRole::Serving->value)->whereHas('serviceInstance', $current)->distinct()->pluck('service_instance_id')->all();

        $ids = array_values(array_unique([...$withData, ...$running]));

        if ($ids === []) {
            return [];
        }

        $instances = ServiceInstance::query()->whereKey($ids)->with(['service', 'environment.project'])->orderBy('id')->limit($limit)->get();
        $out = [];

        foreach ($instances as $instance) {
            $data = in_array($instance->id, $withData, true);
            $label = self::label($instance);

            $out[] = new MoveBlockerData(
                $instance->service->name,
                $instance->environment->project->name,
                $instance->environment->name,
                $server->name,
                $data ? 'data' : 'runs',
                $data ? "{$label} keeps its data on {$server->name}." : "{$label} still runs on {$server->name}.",
                route('projects.services.show', [$instance->environment->project->public_id, $instance->environment->public_id, $instance->service->public_id], absolute: false),
            );
        }

        return $out;
    }

    /**
     * The refusal for $blockers: the first one named, with how many more.
     *
     * @param  non-empty-list<MoveBlockerData>  $blockers
     */
    public static function refusal(array $blockers, string $then): string
    {
        $first = $blockers[0]->message;
        $more = count($blockers) - 1;

        return $more === 0
            ? "{$first} {$then}"
            : "{$first} ".($more === 1 ? '1 more service is' : "{$more} more services are")." still on {$blockers[0]->server}. {$then}";
    }

    /**
     * "web (Shop / production)".
     */
    public static function label(ServiceInstance $instance): string
    {
        return "{$instance->service->name} ({$instance->environment->project->name} / {$instance->environment->name})";
    }
}
