<?php

declare(strict_types=1);

namespace App\Services\Moves;

use App\Actions\Moves\StartServerMove;
use App\Data\Moves\MoveDnsRecordData;
use App\Data\Moves\MovePlanData;
use App\Data\Moves\MovePlanItemData;
use App\Data\Moves\MoveServerData;
use App\Data\Services\DatabaseSource;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Enums\Backups\RestoreRunStatus;
use App\Enums\DaemonFeature;
use App\Enums\DomainKind;
use App\Enums\Moves\MoveItemKind;
use App\Enums\Moves\ServerMoveStatus;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Enums\ServiceKind;
use App\Enums\Transfers\ImportRunStatus;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupRun;
use App\Models\Backups\RestoreRun;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\InstanceSettings;
use App\Models\Moves\ServerMove;
use App\Models\Placement;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Transfers\DataImportRun;
use App\Models\Volume;
use App\Scheduling\BuildCandidate;
use App\Services\Backups\DatabaseWorkloads;
use App\Services\Backups\RestoreHolds;
use App\Services\Builds\BuildRouting;
use App\Services\Dns\DnsCheck;
use App\Services\Instance\DashboardOrigin;
use App\Services\Transfers\ImportTarget;
use App\Services\Transfers\TransferStore;
use App\Support\Hostname;
use App\Support\ServerPlatform;
use App\Support\Servers\HostRoleLimits;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Plans a server move (docs/14 §13.2): every service instance with a
 * workload or a volume on the source, how it moves ({@see MoveItemKind}),
 * what it costs (downtime from the latest backup's size at an assumed
 * transfer rate, rebuilds on an architecture change), which DNS records
 * change, and what refuses the move (blockers) or deserves a look
 * (warnings). The wizard shows it; {@see StartServerMove}
 * checks it again under a lock.
 */
final readonly class MovePlanner
{
    /** Assumed throughput through the dashboard (export, then import): 20 MB/s each way. */
    public const int BYTES_PER_SECOND = 20 * 1000 * 1000;

    /** Stopping, starting and the health check of one stateful service. */
    public const int OVERHEAD_SECONDS = 45;

    /** Free space kept on the target and the control plane on top of the data. */
    public const int MARGIN_BYTES = 1024 * 1024 * 1024;

    public function __construct(
        private DnsCheck $dns,
        private BuildRouting $routing,
        private TransferStore $transfers,
    ) {}

    /**
     * The services on $source and how each moves, stateless first (apps
     * before their workers), then stateful ones (databases before the
     * services with volumes).
     *
     * @return list<array{instance: ServiceInstance, kind: MoveItemKind, expectedBytes: int|null}>
     */
    public function services(Server $source): array
    {
        /** @var list<int> $fromPlacements */
        $fromPlacements = Placement::query()->where('server_id', $source->id)->distinct()->pluck('service_instance_id')->all();
        /** @var list<int> $fromVolumes */
        $fromVolumes = Volume::query()->where('server_id', $source->id)->distinct()->pluck('service_instance_id')->all();

        $instances = ServiceInstance::query()
            ->whereKey(array_values(array_unique([...$fromPlacements, ...$fromVolumes])))
            ->where('team_id', $source->team_id)
            ->whereNull('removed_at')
            ->with(['service', 'environment.project', 'volumes'])
            ->orderBy('id')
            ->get();

        $out = [];

        foreach ($instances as $instance) {
            $kind = self::kindOf($instance, $source);
            $out[] = ['instance' => $instance, 'kind' => $kind, 'expectedBytes' => $kind->isStateful() ? $this->expectedBytes($instance) : null];
        }

        usort($out, static fn (array $a, array $b): int => [self::order($a['instance'], $a['kind']), $a['instance']->id] <=> [self::order($b['instance'], $b['kind']), $b['instance']->id]);

        return $out;
    }

    public static function kindOf(ServiceInstance $instance, Server $source): MoveItemKind
    {
        $onSource = $instance->volumes->contains(static fn (Volume $volume): bool => $volume->server_id === $source->id);

        if (! $onSource) {
            return MoveItemKind::Stateless;
        }

        $database = DatabaseWorkloads::source($instance);

        return $database instanceof DatabaseSource && in_array($database->engine, ImportTarget::IMPORTABLE, true) ? MoveItemKind::Dump : MoveItemKind::Files;
    }

    public function plan(Server $source, ?Server $target, bool $controlPlane = false, bool $checkDns = false): MovePlanData
    {
        $services = $this->services($source);
        $blockers = [];
        $warnings = [];
        $sourcePlatform = ServerPlatform::of($source);
        $targetPlatform = $target instanceof Server ? ServerPlatform::of($target) : null;
        $platformChange = $sourcePlatform !== null && $targetPlatform !== null && $sourcePlatform !== $targetPlatform;
        $stateful = array_values(array_filter($services, static fn (array $entry): bool => $entry['kind']->isStateful()));
        $files = array_values(array_filter($services, static fn (array $entry): bool => $entry['kind'] === MoveItemKind::Files));

        // The source.
        $blockers = [...$blockers, ...$this->sourceBlockers($source, $controlPlane)];

        // The target.
        if ($target instanceof Server) {
            $blockers = [...$blockers, ...$this->targetBlockers($source, $target, $services !== [], $stateful !== [], $files !== [])];
        }

        // Everyone involved must be able to copy data.
        if ($stateful !== [] && ! $source->supportsDaemonFeature(DaemonFeature::DataTransfer)) {
            $blockers[] = "Update launchroomd on {$source->name} first: it can’t copy data off the server yet.";
        }

        foreach ($stateful as $entry) {
            if ($this->busy($entry['instance'])) {
                $blockers[] = "A restore or import into {$entry['instance']->service->name} is running. Wait for it to finish.";
            }
        }

        // An edge that is the team's only one: the target becomes an edge first.
        $edges = $this->edges($source->team_id);
        $sourceIsEdge = $source->hasRole(ServerRole::Edge);
        $onlyEdge = $sourceIsEdge && count(array_filter($edges, static fn (Server $edge): bool => $edge->id !== $source->id)) === 0;
        $addsEdge = $target instanceof Server && $onlyEdge && ! $target->hasRole(ServerRole::Edge);

        if ($addsEdge && ($refusal = HostRoleLimits::refusal($target, ServerRole::Edge)) !== null) {
            $blockers[] = "{$source->name} is the only server that serves your domains, and {$target->name} can’t take over: {$refusal}";
        }

        // Rebuilds on an architecture change.
        $rebuilds = [];

        if ($platformChange) {
            foreach ($services as $entry) {
                if ($this->needsRebuild($entry['instance'], $targetPlatform)) {
                    $rebuilds[$entry['instance']->id] = true;
                }
            }

            if ($rebuilds !== [] && ! $this->canBuild($source->team_id, $targetPlatform, $target)) {
                $blockers[] = "No build server builds {$targetPlatform} images. Give {$target->name} the build role, or add a build server for {$targetPlatform}.";
            }
        }

        // Disk space: the target holds the data, the dashboard each file while it passes through.
        $copyBytes = null;
        $unknown = false;
        $largest = 0;

        foreach ($stateful as $entry) {
            if ($entry['expectedBytes'] === null) {
                $unknown = true;

                continue;
            }

            $copyBytes = ($copyBytes ?? 0) + $entry['expectedBytes'];
            $largest = max($largest, $entry['expectedBytes']);
        }

        if ($target instanceof Server && $copyBytes !== null) {
            $free = MoveServerData::diskFreeMb($target);

            if ($free !== null && $free * 1024 * 1024 < $copyBytes + self::MARGIN_BYTES) {
                $blockers[] = "{$target->name} has ".self::size($free * 1024 * 1024).' free, but the data to move needs about '.self::size($copyBytes + self::MARGIN_BYTES).'. Free up space or pick a bigger server.';
            }
        }

        $dashboardFree = $this->transfers->freeBytes();

        if ($largest > 0 && $dashboardFree !== null && $dashboardFree < $largest + self::MARGIN_BYTES) {
            $blockers[] = 'Each copy passes through the dashboard’s disk, which has '.self::size($dashboardFree).' free; the largest needs about '.self::size($largest + self::MARGIN_BYTES).'.';
        }

        // Items.
        $items = [];
        $total = 0;

        foreach ($services as $entry) {
            $instance = $entry['instance'];
            $kind = $entry['kind'];
            $downtime = match (true) {
                ! $kind->isStateful() => 0,
                $entry['expectedBytes'] === null => null,
                default => self::downtimeFor($entry['expectedBytes']),
            };
            $total = $downtime === null || $total === null ? null : $total + $downtime;
            $stops = $kind->isStateful() ? $this->stops($instance, $kind) : [];

            $items[] = new MovePlanItemData(
                $instance->public_id,
                $instance->service->name,
                "{$instance->service->name} ({$instance->environment->project->name} / {$instance->environment->name})",
                $kind,
                $kind === MoveItemKind::Stateless ? null : (DatabaseWorkloads::source($instance)?->engine->label() ?? 'Files'),
                $entry['expectedBytes'],
                $downtime,
                isset($rebuilds[$instance->id]),
                $stops,
                $this->note($kind, isset($rebuilds[$instance->id]), $targetPlatform),
            );
        }

        if ($unknown) {
            $warnings[] = 'Some data has never been backed up, so its size is unknown and the downtime estimate is missing for it. It still moves; a backup first makes the estimate exact.';
        }

        $sslip = $this->sslipDomains($source);

        if ($sslip > 0) {
            $warnings[] = "{$sslip} generated ".($sslip === 1 ? 'address contains' : 'addresses contain')." {$source->name}’s IP address (….sslip.io). They stop working once {$source->name} is gone: set a platform domain in Settings → Network and add the apps’ domains there, or add custom domains.";
        }

        return new MovePlanData(
            MoveServerData::fromServer($source),
            $target instanceof Server ? MoveServerData::fromServer($target) : null,
            $items,
            array_values(array_unique($blockers)),
            $warnings,
            $target instanceof Server && $sourceIsEdge ? $this->dnsRecords($source, $target, $edges, $onlyEdge, $controlPlane, $checkDns) : [],
            $platformChange ? $sourcePlatform : null,
            $platformChange ? $targetPlatform : null,
            $addsEdge,
            $stateful === [] ? 0 : $total,
            $copyBytes,
            BackupDestination::defaultFor($source->team_id) instanceof BackupDestination,
        );
    }

    /**
     * Seconds a stateful service is down for $bytes of data: copied out and
     * in at the assumed rate, plus stopping and starting.
     */
    public static function downtimeFor(int $bytes): int
    {
        return self::OVERHEAD_SECONDS + (int) ceil(2 * $bytes / self::BYTES_PER_SECOND);
    }

    /**
     * Whether $instance's image must be built again for $platform: an app
     * built from source whose current image is for another platform.
     * Workers and crons run their app's image and follow it.
     */
    public function needsRebuild(ServiceInstance $instance, string $platform): bool
    {
        if ($instance->service->kind !== ServiceKind::App || $instance->active_deployment_id === null) {
            return false;
        }

        $active = Deployment::query()->find($instance->active_deployment_id);
        $current = $active?->plan['platform'] ?? null;

        return is_string($current) && $current !== $platform;
    }

    /**
     * @return list<string>
     */
    private function sourceBlockers(Server $source, bool $controlPlane): array
    {
        return array_values(array_filter([
            match (true) {
                $source->status === ServerStatus::Revoked => "{$source->name} was revoked; there is nothing left to move.",
                $source->status === ServerStatus::PendingApproval => "{$source->name} was never approved.",
                $source->status !== ServerStatus::Online => "{$source->name} is {$source->status->value}. A move copies data off a running server; for a server that is gone, use Replace server.",
                default => null,
            },
            $source->is_control_plane && ! $controlPlane ? "{$source->name} runs the dashboard. Move it with Settings → Move launchroom, which also moves the dashboard." : null,
            $this->openMove($source) instanceof ServerMove ? "{$source->name} is already part of a move." : null,
        ]));
    }

    /**
     * @return list<string>
     */
    private function targetBlockers(Server $source, Server $target, bool $hasServices, bool $hasStateful, bool $hasFiles): array
    {
        $mesh = InstanceSettings::current()->mesh_enabled_at !== null;

        return array_values(array_filter([
            $target->id === $source->id || $target->team_id !== $source->team_id ? 'Pick another server of the same team.' : null,
            $target->status !== ServerStatus::Online ? "{$target->name} must be online." : null,
            $target->replaced_by_server_id !== null ? "{$target->name} was replaced." : null,
            $this->openMove($target) instanceof ServerMove ? "{$target->name} is already part of a move." : null,
            $hasServices && HostRoleLimits::refusal($target, ServerRole::Runtime) !== null ? "{$target->name} can’t run apps: ".HostRoleLimits::refusal($target, ServerRole::Runtime) : null,
            $hasServices && ! $target->hasRole(ServerRole::Runtime) ? "{$target->name} doesn’t run apps. Turn on Run apps in its settings first." : null,
            $hasStateful && ! $target->supportsDaemonFeature(DaemonFeature::DataTransfer) ? "Update launchroomd on {$target->name} first: it can’t receive data yet." : null,
            $hasFiles && ! $target->supportsDaemonFeature(DaemonFeature::PreparedVolumes) ? "Update launchroomd on {$target->name} first: it can’t receive volumes yet." : null,
            $hasServices && ! $mesh ? 'The private network between your servers is off. Turn it on in Servers → Private network first.' : null,
            $hasServices && $mesh && ($target->mesh_ip === null || $source->mesh_ip === null) ? ($target->mesh_ip === null ? "{$target->name}" : "{$source->name}").' hasn’t joined the private network yet. Wait a minute and check again.' : null,
        ]));
    }

    /**
     * The open move $server is part of (as source or target), if any.
     */
    public function openMove(Server $server): ?ServerMove
    {
        return ServerMove::query()
            ->whereIn('status', ServerMoveStatus::values(ServerMoveStatus::open()))
            ->where(static fn (Builder $query) => $query->where('source_server_id', $server->id)->orWhere('target_server_id', $server->id))
            ->first();
    }

    private function busy(ServiceInstance $instance): bool
    {
        return DataImportRun::query()
            ->where('service_instance_id', $instance->id)
            ->whereIn('status', [ImportRunStatus::WaitingForSafetyBackup->value, ImportRunStatus::Holding->value, ImportRunStatus::Importing->value])
            ->exists()
            || RestoreRun::query()
                ->where('target_service_instance_id', $instance->id)
                ->whereNotIn('status', [RestoreRunStatus::Succeeded->value, RestoreRunStatus::Failed->value])
                ->exists();
    }

    /**
     * Bytes to copy: the latest successful backup's size (the dump, or the
     * sum of the volumes' snapshots); null when there is none.
     */
    private function expectedBytes(ServiceInstance $instance): ?int
    {
        $runs = BackupRun::query()
            ->where('service_instance_id', $instance->id)
            ->where('status', BackupRunStatus::Succeeded->value)
            ->whereNotNull('size_bytes')
            ->latest('finished_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'volume_id', 'size_bytes', 'subject']);

        if (DatabaseWorkloads::source($instance) instanceof DatabaseSource) {
            // The latest dump (base backups for point-in-time recovery are physical, and bigger).
            $run = $runs->first(static fn (BackupRun $run): bool => $run->volume_id === null && $run->subject === BackupSubject::Database);

            return $run?->size_bytes;
        }

        $total = 0;

        foreach ($instance->volumes as $volume) {
            $run = $runs->first(static fn (BackupRun $run): bool => $run->volume_id === $volume->id);

            if (! $run instanceof BackupRun || $run->size_bytes === null) {
                return null;
            }

            $total += $run->size_bytes;
        }

        return $total;
    }

    /**
     * @return list<string> names of the services that stop while it is copied
     */
    private function stops(ServiceInstance $instance, MoveItemKind $kind): array
    {
        $ids = DatabaseWorkloads::source($instance) instanceof DatabaseSource ? RestoreHolds::dependentIds($instance) : [];

        if ($kind === MoveItemKind::Files) {
            $ids = [$instance->id, ...$ids];
        }

        if ($ids === []) {
            return [];
        }

        /** @var list<string> $names */
        $names = ServiceInstance::query()->whereKey($ids)->with('service')->get()->map(static fn (ServiceInstance $i): string => $i->service->name)->unique()->values()->all();

        return $names;
    }

    private function note(MoveItemKind $kind, bool $rebuild, ?string $platform): string
    {
        return match ($kind) {
            MoveItemKind::Stateless => $rebuild ? "Built for {$platform}, then switched over without downtime." : 'Starts on the new server, then the old copy stops. No downtime.',
            MoveItemKind::Dump => 'Its apps stop, a final dump is copied over and loaded, then they start against the new server.',
            MoveItemKind::Files => ($rebuild ? "Built for {$platform} first, while it still runs. " : '').'It stops, its files are copied over, then it starts on the new server.',
        };
    }

    private function canBuild(int $teamId, string $platform, ?Server $target): bool
    {
        if ($target instanceof Server && $target->hasRole(ServerRole::Build) && in_array($platform, ServerPlatform::buildable($target), true)) {
            return true;
        }

        return array_any($this->routing->builders($teamId), fn (BuildCandidate $builder): bool => $builder->builds($platform));
    }

    /**
     * @return list<Server>
     */
    private function edges(int $teamId): array
    {
        return array_values(Server::query()
            ->where('team_id', $teamId)
            ->whereIn('status', [ServerStatus::Online->value, ServerStatus::Unreachable->value, ServerStatus::Offline->value])
            ->whereJsonContains('roles', ServerRole::Edge->value)
            ->orderBy('id')
            ->get()
            ->all());
    }

    /**
     * Records that point at the source and must point at the target (or at
     * the remaining edges) once the source is gone.
     *
     * @param  list<Server>  $edges
     * @return list<MoveDnsRecordData>
     */
    private function dnsRecords(Server $source, Server $target, array $edges, bool $onlyEdge, bool $controlPlane, bool $check): array
    {
        $remove = array_values(array_filter([$source->public_ipv4, $source->public_ipv6], is_string(...)));
        $after = [];

        foreach ($edges as $edge) {
            if ($edge->id !== $source->id) {
                $after = [...$after, ...array_filter([$edge->public_ipv4, $edge->public_ipv6], is_string(...))];
            }
        }

        if ($onlyEdge || $target->hasRole(ServerRole::Edge)) {
            $after = [...$after, ...array_filter([$target->public_ipv4, $target->public_ipv6], is_string(...))];
        }

        $after = array_values(array_unique($after));
        $hosts = [];

        if ($controlPlane) {
            $host = parse_url(resolve(DashboardOrigin::class)->canonical(), PHP_URL_HOST);

            if (is_string($host) && $host !== '' && ! Hostname::isLocal($host) && ! str_ends_with($host, '.sslip.io')) {
                $hosts[] = ['dashboard', $host];
            }
        }

        $apps = InstanceSettings::current()->apps_domain;

        if (is_string($apps) && $apps !== '') {
            $hosts[] = ['apps', '*.'.$apps];
        }

        $custom = Domain::query()
            ->where('kind', DomainKind::Custom->value)
            ->whereHas('serviceInstance', static fn (Builder $query) => $query->where('team_id', $source->team_id)->whereNull('removed_at'))
            ->orderBy('hostname')
            ->limit(100)
            ->pluck('hostname')
            ->all();

        foreach ($custom as $hostname) {
            if (is_string($hostname)) {
                $hosts[] = ['domain', $hostname];
            }
        }

        return array_map(fn (array $entry): MoveDnsRecordData => new MoveDnsRecordData(
            $entry[0],
            $entry[1],
            $after,
            $remove,
            $check && ! Hostname::isLocal(ltrim($entry[1], '*.')) ? ($entry[0] === 'apps' ? $this->dns->wildcard(substr($entry[1], 2), $after) : $this->dns->hostname($entry[1], $after)) : null,
        ), $hosts);
    }

    private function sslipDomains(Server $source): int
    {
        if ($source->public_ipv4 === null) {
            return 0;
        }

        return Domain::query()
            ->where('kind', DomainKind::Generated->value)
            ->where('hostname', 'like', '%.'.str_replace('.', '-', $source->public_ipv4).'.sslip.io')
            ->whereHas('serviceInstance', static fn (Builder $query) => $query->where('team_id', $source->team_id)->whereNull('removed_at'))
            ->count();
    }

    private static function order(ServiceInstance $instance, MoveItemKind $kind): int
    {
        return match (true) {
            $kind === MoveItemKind::Stateless && ! $instance->service->kind->requiresParent() => 0,
            $kind === MoveItemKind::Stateless => 1,
            DatabaseWorkloads::source($instance) instanceof DatabaseSource => 2,
            default => 3,
        };
    }

    public static function size(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1000 && $unit < count($units) - 1) {
            $value /= 1000;
            $unit++;
        }

        return ($unit === 0 ? (string) (int) $value : number_format($value, $value < 10 ? 1 : 0)).' '.$units[$unit];
    }
}
