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
     * at the first deploy), in a stable order.
     *
     * @return list<Volume>
     */
    public static function placedVolumes(ServiceInstance $instance): array
    {
        $instance->loadMissing('volumes.server');

        return array_values($instance->volumes->filter(static fn (Volume $volume): bool => $volume->server_id !== null)->sortBy('id')->all());
    }
}
