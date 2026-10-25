<?php

declare(strict_types=1);

namespace App\Queries\RemoteAccess;

use App\Data\RemoteAccess\OpenRemoteAccessData;
use App\Data\RemoteAccess\RemoteAccessData;
use App\Data\RemoteAccess\RemoteAccessPanelData;
use App\Data\Services\DatabaseSource;
use App\Data\Sessions\SessionReplicaData;
use App\Enums\Permission;
use App\Enums\RemoteAccess\RemoteAccessKind;
use App\Enums\RemoteAccess\RemoteAccessStatus;
use App\Enums\ServiceKind;
use App\Models\Placement;
use App\Models\RemoteAccess\RemoteAccess;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Models\Volume;
use App\Services\RemoteAccess\RemoteAccessTargets;
use App\Services\RemoteAccess\RemoteAccessView;
use App\Support\RemoteAccess\AccessPrefix;

/**
 * Read model of the "Connect via SSH", "Connect with SFTP" (a volume) and
 * "Temporary access" panels.
 */
final readonly class RemoteAccessPanel
{
    private const int RECENT = 5;

    public function __construct(
        private RemoteAccessTargets $targets,
    ) {}

    public function panel(ServiceInstance $instance, RemoteAccessKind $kind, User $user, ?string $requesterIp, ?string $volumeId = null): ?RemoteAccessPanelData
    {
        $replicas = [];
        $engineLabel = null;
        $volume = null;
        $instance->loadMissing('service', 'environment');

        if ($kind === RemoteAccessKind::Volume) {
            // Through the instance's own volumes (scoped; removed ones are gone).
            $volume = $volumeId === null ? null : $instance->volumes()->with('server:id,name')->where('public_id', $volumeId)->first();

            if (! $volume instanceof Volume) {
                return null;
            }

            $reason = match (true) {
                $volume->server_id === null => 'This volume is created with the next deploy. Deploy the service, then connect.',
                ! $this->targets->volumeReplica($instance, $volume) instanceof Placement => ($volume->server->name ?? 'Its server').' doesn’t allow SFTP into volumes right now. Deploy the service, or update the server in Settings → Updates.',
                default => null,
            };
        } elseif ($kind === RemoteAccessKind::Database) {
            $source = $this->targets->database($instance);

            if (! $source instanceof DatabaseSource) {
                return null;
            }

            $engineLabel = $source->engine->label();
            $placement = $this->targets->databaseReplica($instance);
            $reason = match (true) {
                ! RemoteAccessTargets::engineSupported($source->engine) => $engineLabel.' has no temporary access yet. Use the Data tab.',
                ! $placement instanceof Placement => $engineLabel.' isn’t running on a server that allows temporary access. Deploy it, or update its server in Settings → Updates.',
                default => null,
            };
        } else {
            $placements = $this->targets->replicas($instance);
            $replicas = array_values($placements->map(static fn (Placement $placement): SessionReplicaData => SessionReplicaData::fromPlacement($placement))->all());
            $reason = $replicas === [] ? 'No replica runs on a server that allows SSH access. Deploy the service, or check the server’s LR_REMOTE_ACCESS setting.' : null;
        }

        if (config('launchroom.remote_access.enabled') !== true) {
            $reason = 'Temporary access is turned off on this launchroom.';
        }

        $max = config()->integer('launchroom.remote_access.max_minutes');
        $durations = array_values(array_filter(OpenRemoteAccessData::DURATIONS, static fn (int $minutes): bool => $minutes <= $max));

        return new RemoteAccessPanelData(
            kind: $kind,
            available: $reason === null,
            unavailableReason: $reason,
            requesterPrefix: AccessPrefix::forAddress($requesterIp),
            replicas: $replicas,
            engineLabel: $engineLabel,
            idleMinutes: config()->integer('launchroom.remote_access.idle_minutes'),
            durations: $durations,
            defaultDuration: in_array(120, $durations, true) ? 120 : (int) max([30, ...$durations]),
            accesses: $this->accesses($instance, $kind, $user, $volume),
            serviceName: $instance->service->name,
            environmentName: $instance->environment->name,
            volumeId: $volume?->public_id,
            mountPath: $volume?->mount_path,
            volumeServer: $volume?->server?->name,
            // A database engine's files change under it: read-only (D67).
            writable: $volume instanceof Volume && $instance->service->kind !== ServiceKind::Database,
        );
    }

    /**
     * @return list<RemoteAccessData>
     */
    private function accesses(ServiceInstance $instance, RemoteAccessKind $kind, User $user, ?Volume $volume): array
    {
        $everyone = $user->hasTeamPermission($instance->team_id, Permission::ManageMembers);

        return array_values(RemoteAccess::query()
            ->where('service_instance_id', $instance->id)
            ->where('kind', $kind->value)
            ->when($volume instanceof Volume, static fn ($query) => $query->where('volume_id', $volume?->id))
            ->unless($everyone, static fn ($query) => $query->where('user_id', $user->id))
            ->orderByRaw('CASE WHEN status = ? THEN 1 ELSE 0 END', [RemoteAccessStatus::Ended->value])
            ->latest('id')
            ->limit(self::RECENT)
            ->get()
            ->map(static fn (RemoteAccess $access): RemoteAccessData => RemoteAccessView::data($access, $user))
            ->all());
    }
}
