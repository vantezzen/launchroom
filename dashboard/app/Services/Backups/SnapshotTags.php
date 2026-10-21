<?php

declare(strict_types=1);

namespace App\Services\Backups;

use App\Enums\Backups\BackupReason;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Volume;
use App\Support\PublicId;

/**
 * Snapshot tags (docs/14 §2): restore UIs are built from `restic snapshots
 * --json` filtered by these, so a fresh dashboard can find every backup in a
 * repository without a database of its own. Grammar: protocol BackupTag
 * `key:value`, value `[A-Za-z0-9_.-]{1,128}`.
 */
final class SnapshotTags
{
    /**
     * @return list<string>
     */
    public static function forDatabase(ServiceInstance $instance, Server $server, string $engine, BackupReason $reason): array
    {
        $instance->loadMissing(['service.project.team', 'environment.project']);
        $environment = $instance->environment;

        return [
            'instance:'.self::instanceId(),
            'kind:database',
            'team:'.$instance->service->project->team->public_id,
            'project:'.$environment->project->public_id,
            'environment:'.$environment->public_id,
            'service:'.$instance->service->public_id,
            'server:'.$server->public_id,
            'engine:'.$engine,
            'reason:'.$reason->value,
        ];
    }

    /**
     * The tags every snapshot of one database carries (restore listing and
     * per-database retention).
     *
     * @return list<string>
     */
    public static function databaseFilter(ServiceInstance $instance): array
    {
        $instance->loadMissing(['service', 'environment']);

        return ['kind:database', 'environment:'.$instance->environment->public_id, 'service:'.$instance->service->public_id];
    }

    /**
     * Tags of a volume snapshot (docs/14 §2): one volume of a service that is
     * not a database.
     *
     * @return list<string>
     */
    public static function forVolume(ServiceInstance $instance, Volume $volume, Server $server, BackupReason $reason): array
    {
        $instance->loadMissing(['service.project.team', 'environment.project']);
        $environment = $instance->environment;

        return [
            'instance:'.self::instanceId(),
            'kind:volume',
            'team:'.$instance->service->project->team->public_id,
            'project:'.$environment->project->public_id,
            'environment:'.$environment->public_id,
            'service:'.$instance->service->public_id,
            'server:'.$server->public_id,
            'volume:'.$volume->public_id,
            'reason:'.$reason->value,
        ];
    }

    /**
     * The tags every volume snapshot of a service carries (restore listing);
     * with $volume, of that volume only (per-volume retention).
     *
     * @return list<string>
     */
    public static function volumeFilter(ServiceInstance $instance, ?Volume $volume = null): array
    {
        $instance->loadMissing(['service', 'environment']);
        $tags = ['kind:volume', 'environment:'.$instance->environment->public_id, 'service:'.$instance->service->public_id];

        return $volume instanceof Volume ? [...$tags, 'volume:'.$volume->public_id] : $tags;
    }

    /**
     * @return list<string>
     */
    public static function forControlPlane(string $runId, string $part, BackupReason $reason): array
    {
        return [
            'instance:'.self::instanceId(),
            'kind:control-plane',
            'run:'.$runId,
            'part:'.$part,
            'reason:'.$reason->value,
        ];
    }

    /**
     * A stable id for this launchroom instance (survives a control-plane
     * restore because it lives in the restored database).
     */
    public static function instanceId(): string
    {
        $settings = InstanceSettings::current();

        if ($settings->instance_id === null) {
            InstanceSettings::query()->whereKey(InstanceSettings::ID)->whereNull('instance_id')
                ->update(['instance_id' => PublicId::generate('ins')]);
            $settings = InstanceSettings::current();
        }

        return (string) $settings->instance_id;
    }

    /**
     * Value of `key:` in a tag list, or null.
     *
     * @param  list<string>  $tags
     */
    public static function value(array $tags, string $key): ?string
    {
        foreach ($tags as $tag) {
            if (str_starts_with($tag, $key.':')) {
                return substr($tag, strlen($key) + 1);
            }
        }

        return null;
    }
}
