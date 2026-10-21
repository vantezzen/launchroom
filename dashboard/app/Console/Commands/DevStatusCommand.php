<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Models\Deployment;
use App\Models\Placement;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Queries\DeploymentLogs;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Local dev only: a service instance's latest deployments (status, commit,
 * build server) and placements (server, short container id, phase, role) as
 * JSON (plus the team's servers with status and roles), or with `--build-log` the plan/build log of one of its deployments
 * as text. The failover test (dev/failover-test/failover.sh) waits on it and
 * maps the replica that answered a request (the fixture reports its
 * container hostname = short container id) to its server. Refuses outside
 * `local`.
 */
#[Signature('launchroom:dev:status {instance : Service instance id (sin_…)} {--build-log= : Print the plan/build log of this deployment (dpl_…) instead}')]
#[Description('Local dev only: deployments and placements of a service instance as JSON')]
final class DevStatusCommand extends Command
{
    public function handle(DeploymentLogs $logs): int
    {
        if (! app()->environment('local')) {
            $this->components->error('This command only runs in the local dev environment.');

            return self::FAILURE;
        }

        $ref = $this->argument('instance');
        $instance = ServiceInstance::query()->where('public_id', is_string($ref) ? $ref : '')->first();

        if (! $instance instanceof ServiceInstance) {
            $this->components->error('Unknown service instance.');

            return self::FAILURE;
        }

        $buildLog = $this->option('build-log');

        if (is_string($buildLog) && $buildLog !== '') {
            $deployment = Deployment::query()->where('service_instance_id', $instance->id)->where('public_id', $buildLog)->first();

            if (! $deployment instanceof Deployment) {
                $this->components->error('Unknown deployment of this instance.');

                return self::FAILURE;
            }

            $after = 0;

            do {
                $page = $logs->page($deployment, $after);

                foreach ($page->lines as $line) {
                    if (in_array($line->section, ['plan', 'build'], true)) {
                        $this->line("[{$line->section}] {$line->text}");
                    }
                }

                $after = $page->cursor;
            } while ($page->hasMore);

            return self::SUCCESS;
        }

        $team = Server::query()->where('team_id', $instance->team_id)->where('status', '!=', ServerStatus::Revoked->value)->orderBy('id')->get();
        $servers = $team->pluck('name', 'id')->all();

        $deployments = Deployment::query()
            ->where('service_instance_id', $instance->id)
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(static fn (Deployment $d): array => [
                'id' => $d->public_id,
                'status' => $d->status->value,
                'commit' => $d->commit_sha,
                'buildServer' => $d->build_server_id === null ? null : ($servers[$d->build_server_id] ?? null),
                'failure' => $d->failure_message,
            ])
            ->all();

        $placements = Placement::query()
            ->where('service_instance_id', $instance->id)
            ->with('deployment')
            ->orderBy('id')
            ->get()
            ->map(static fn (Placement $p): array => [
                'server' => $servers[$p->server_id] ?? null,
                'deployment' => $p->deployment->public_id,
                'container' => $p->container_id === null ? null : substr($p->container_id, 0, 12),
                'phase' => $p->phase->value,
                'role' => $p->role->value,
            ])
            ->all();

        $this->line((string) json_encode([
            'deployments' => $deployments,
            'placements' => $placements,
            'placementIssue' => $instance->placement_issue,
            'servers' => $team->map(static fn (Server $s): array => [
                'name' => $s->name,
                'status' => $s->status->value,
                'roles' => $s->roles->map(static fn (ServerRole $r): string => $r->value)->values()->all(),
            ])->all(),
        ], JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
