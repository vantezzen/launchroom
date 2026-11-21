<?php

declare(strict_types=1);

namespace App\Services\Transfers;

use App\Data\Services\DatabaseSource;
use App\Drivers\DatabaseEngines\DumpImport;
use App\Enums\Transfers\DataSubject;
use App\Exceptions\DomainRuleViolation;
use App\Models\ServiceInstance;
use App\Models\Volume;
use App\Services\Backups\DatabaseWorkloads;
use App\Support\PublicId;

/**
 * The rules of a copy between environments (D110, docs/14 §14.3): which
 * targets take one, which sources fit a target, how volumes pair up and how
 * long the target stops. Permissions are the policy's
 * (DataCopyRunPolicy); this answers "can
 * these two be copied at all" and why not, in the user's words.
 */
final class CopyRules
{
    /** Dump bytes loaded per minute, for the estimate of how long the target stops (rounded up). */
    public const int BYTES_PER_MINUTE = 1536 * 1024 * 1024;

    /** Stopping and starting the apps around the load. */
    public const int STOP_BASE_SECONDS = 30;

    /**
     * Why nothing can be copied into $target, or null.
     */
    public static function targetRefusal(ServiceInstance $target): ?string
    {
        $target->loadMissing(['service', 'environment']);
        $subject = ImportTarget::for($target);

        if (! $subject->subject instanceof DataSubject) {
            return 'Only databases and services with volumes hold data to copy.';
        }

        if (! $subject->importSupported()) {
            $engine = $subject->database?->engine->label() ?? 'this engine';

            return "Copying {$engine} data isn’t supported yet. Restore a backup into it instead.";
        }

        // Fork previews never receive the base's data (docs/09 R20).
        if ($target->environment->secrets_withheld) {
            return 'This preview comes from a fork, so it never receives data from other environments.';
        }

        if ($target->stopped_at !== null) {
            return "{$target->service->name} is stopped. Start it, then copy data into it.";
        }

        return $subject->unavailableReason();
    }

    /**
     * Why $source can't be copied into $target, or null. Both are current
     * instances; the caller made sure they belong to the same team.
     */
    public static function sourceRefusal(ServiceInstance $source, ServiceInstance $target): ?string
    {
        $source->loadMissing(['service', 'environment', 'volumes']);
        $target->loadMissing(['service', 'environment', 'volumes']);

        if ($source->id === $target->id || $source->service_id !== $target->service_id || $source->environment->project_id !== $target->environment->project_id) {
            return 'Pick the same service in another environment of this project.';
        }

        if ($source->stopped_at !== null) {
            return "{$source->service->name} is stopped in {$source->environment->name}.";
        }

        $from = DatabaseWorkloads::source($source);
        $to = DatabaseWorkloads::source($target);

        if ($to instanceof DatabaseSource) {
            if (! $from instanceof DatabaseSource) {
                return "{$source->service->name} isn’t a database in {$source->environment->name}.";
            }

            return self::versionRefusal($from, $to, $source->environment->name, $target->environment->name, $target->service->name);
        }

        if ($source->volumes->isEmpty()) {
            return "{$source->service->name} has no volumes in {$source->environment->name}.";
        }

        return null;
    }

    /**
     * Engines must match (one import family) and a dump never goes into an
     * older major version. Also the rule of preview clones (docs/14 §14.5).
     */
    public static function versionRefusal(DatabaseSource $from, DatabaseSource $to, string $fromName, string $toName, string $serviceName): ?string
    {
        $import = ImportTarget::importFor($to->engine);

        if (! $import instanceof DumpImport) {
            return "Copying {$to->engine->label()} data isn’t supported yet.";
        }

        $fromImport = ImportTarget::importFor($from->engine);

        if (! $fromImport instanceof DumpImport || $fromImport->engine !== $import->engine) {
            return "{$fromName} runs {$from->engine->label()} and {$toName} runs {$to->engine->label()}, so the data can’t be copied.";
        }

        $label = $to->engine->label();

        if (version_compare($import->major($from->version), $import->major($to->version), '>')) {
            return "{$fromName} runs {$label} {$from->version} and {$toName} runs {$label} {$to->version}. A dump can’t go into an older major version. Pick {$from->version} for {$toName}’s {$serviceName} first.";
        }

        return null;
    }

    /**
     * The source's volume that holds what $target holds (same mount path).
     */
    public static function pairedVolume(ServiceInstance $source, Volume $target): ?Volume
    {
        $source->loadMissing('volumes');

        return $source->volumes->firstWhere('mount_path', $target->mount_path);
    }

    /**
     * Roughly how long the target and its apps stop, in seconds: a fixed
     * part for stopping and starting plus the load (docs/14 §14.3).
     */
    public static function estimatedStopSeconds(?int $bytes): ?int
    {
        if ($bytes === null || $bytes < 0) {
            return null;
        }

        return self::STOP_BASE_SECONDS + (int) ceil($bytes / self::BYTES_PER_MINUTE) * 60;
    }

    /**
     * The same service as $target in the environment $environmentId of its
     * project, or a violation.
     */
    public static function sourceFor(ServiceInstance $target, string $environmentId): ServiceInstance
    {
        $target->loadMissing('environment');

        $source = PublicId::isValid($environmentId, 'env') ? ServiceInstance::query()
            ->current()
            ->where('team_id', $target->team_id)
            ->where('service_id', $target->service_id)
            ->whereKeyNot($target->id)
            ->whereHas('environment', static fn ($query) => $query->where('public_id', $environmentId)->where('project_id', $target->environment->project_id))
            ->with(['service', 'environment', 'volumes'])
            ->first() : null;

        if (! $source instanceof ServiceInstance) {
            throw DomainRuleViolation::on('source', 'Pick another environment of this project that has this service.');
        }

        return $source;
    }
}
