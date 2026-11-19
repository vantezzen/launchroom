<?php

declare(strict_types=1);

namespace App\Services\Backups;

use App\Data\Services\DatabaseSource;
use App\Enums\Backups\BackupSubject;
use App\Enums\ServiceKind;
use App\Models\ServiceInstance;
use App\Models\Volume;

/**
 * What protects a service's data (docs/14 §1–2): databases are dumped
 * (never file-copied while running), every other service with volumes gets
 * volume snapshots, everything else holds no data worth backing up.
 */
final class BackupSubjects
{
    public static function for(ServiceInstance $instance): ?BackupSubject
    {
        $instance->loadMissing(['service', 'volumes']);

        if ($instance->service->kind === ServiceKind::Database) {
            return $instance->service->source instanceof DatabaseSource ? BackupSubject::Database : null;
        }

        return $instance->volumes->isNotEmpty() ? BackupSubject::Volume : null;
    }

    /**
     * The volumes of a volume-backed service that exist on a server (placed
     * at the first deploy), in a stable order. Includes volumes whose
     * backups are turned off: their existing snapshots still expire by the
     * schedule's retention (RunBackupMaintenance).
     *
     * @return list<Volume>
     */
    public static function placedVolumes(ServiceInstance $instance): array
    {
        $instance->loadMissing('volumes.server');

        return array_values($instance->volumes->filter(static fn (Volume $volume): bool => $volume->server_id !== null)->sortBy('id')->all());
    }

    /**
     * The placed volumes that are backed up: "Back up this volume" is on.
     * Everything that backs volumes up or expects a volume backup goes
     * through here (scheduled and manual backups, safety snapshots, final
     * backups; verification, alerts, replace-server and volume retirement
     * check {@see self::excluded()}).
     *
     * @return list<Volume>
     */
    public static function backedUpVolumes(ServiceInstance $instance): array
    {
        return array_values(array_filter(self::placedVolumes($instance), static fn (Volume $volume): bool => $volume->backup_enabled));
    }

    /**
     * A volume left out of backups. Only volumes of services that are not
     * databases can be turned off (SetVolumeBackup refuses the others: a
     * database is backed up as a dump, not as its volume).
     */
    public static function excluded(Volume $volume): bool
    {
        return ! $volume->backup_enabled;
    }

    /**
     * Every placed volume of a volume-backed service is turned off: the
     * service is placed but has nothing to back up.
     */
    public static function allExcluded(ServiceInstance $instance): bool
    {
        $placed = self::placedVolumes($instance);

        return self::for($instance) === BackupSubject::Volume && $placed !== [] && self::backedUpVolumes($instance) === [];
    }

    /**
     * A mount path that usually holds a cache (the Backups page suggests
     * turning its backups off; they stay on by default).
     */
    public static function looksLikeCache(string $mountPath): bool
    {
        $path = mb_strtolower($mountPath);

        return preg_match('~(^|/)(tmp|cache|\.cache|caches|node_modules)(/|$)~', $path) === 1;
    }
}
