<?php

declare(strict_types=1);

namespace App\Services\Commands;

use App\Data\Commands\CommandTarget;
use App\Data\Protocol\V1\Tasks\RunOnceMount;
use App\Data\Specs\ServiceSpec;
use App\Enums\DaemonFeature;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Placement;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Volume;

/**
 * Picks the server a one-off command runs on (D105, docs/03 §7.5).
 *
 * A service with volumes runs it where they are, with them mounted
 * read-write at the service's paths, like its release command (G62): a
 * migration of an SQLite app writes to the app's database, `storage:link`
 * where the app reads. Unlike a release command it never falls back to
 * running without them: a command that silently wrote to a throwaway copy
 * would report success for nothing. Volumes shared from other services
 * (D73) are not mounted.
 *
 * Without volumes: a server that runs the live version (its network and the
 * aliases of its databases are there), else any online runtime server, where
 * launchroomd creates the environment network for the task.
 */
final class CommandPlacement
{
    public function for(ServiceInstance $instance, ServiceSpec $spec): CommandTarget
    {
        $volumes = $instance->volumes()->whereNotNull('server_id')->orderBy('id')->get();

        if ($volumes->isNotEmpty()) {
            return $this->withVolumes($instance, $spec, array_values($volumes->all()));
        }

        $online = Server::query()
            ->where('team_id', $instance->team_id)
            ->where('status', ServerStatus::Online->value)
            ->whereJsonContains('roles', ServerRole::Runtime->value)
            ->orderBy('id')
            ->get();

        if ($online->isEmpty()) {
            throw DomainRuleViolation::conflict('No server is online to run it. Commands run on one of your servers.');
        }

        $ready = $online->filter(static fn (Server $server): bool => $server->supportsDaemonFeature(DaemonFeature::CommandRuns));

        if ($ready->isEmpty()) {
            throw DomainRuleViolation::conflict($this->needsUpdate($online->first()));
        }

        $serving = Placement::query()
            ->where('service_instance_id', $instance->id)
            ->where('deployment_id', $instance->active_deployment_id ?? 0)
            ->orderBy('slot')
            ->pluck('server_id')
            ->all();

        foreach ($serving as $id) {
            $server = $ready->firstWhere('id', $id);

            if ($server instanceof Server) {
                return new CommandTarget($server);
            }
        }

        return new CommandTarget($ready->firstOrFail());
    }

    /**
     * @param  list<Volume>  $volumes  the instance's own placed volumes
     */
    private function withVolumes(ServiceInstance $instance, ServiceSpec $spec, array $volumes): CommandTarget
    {
        $server = Server::query()->where('team_id', $instance->team_id)->whereKey($volumes[0]->server_id)->first();

        if (! $server instanceof Server) {
            throw DomainRuleViolation::conflict('The server that holds this service’s volumes is gone.');
        }

        if ($server->status !== ServerStatus::Online) {
            throw DomainRuleViolation::conflict("{$server->name} holds this service’s volumes and is offline. Commands run where the volumes are.");
        }

        if (! $server->hasRole(ServerRole::Runtime)) {
            throw DomainRuleViolation::conflict("{$server->name} holds this service’s volumes but doesn’t run apps.");
        }

        foreach ([DaemonFeature::CommandRuns, DaemonFeature::ReleaseMounts, DaemonFeature::PreparedVolumes] as $feature) {
            if (! $server->supportsDaemonFeature($feature)) {
                throw DomainRuleViolation::conflict($this->needsUpdate($server));
            }
        }

        $mounts = [];

        foreach ($volumes as $volume) {
            if ($volume->server_id === $server->id) {
                $mounts[] = new RunOnceMount($volume->public_id, $spec->volumes[$volume->public_id]->mountPath ?? $volume->mount_path);
            }
        }

        return new CommandTarget($server, $mounts);
    }

    private function needsUpdate(?Server $server): string
    {
        return $server instanceof Server
            ? "Update launchroom on {$server->name} to run commands there."
            : 'Update launchroom on your servers to run commands.';
    }
}
