<?php

declare(strict_types=1);

namespace App\Services\Backups;

use App\Enums\Backups\RestoreMode;
use App\Enums\Backups\RestoreRunStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Backups\RestoreRun;
use App\Models\ServiceInstance;

/**
 * Whether a restore as a new service can be swapped in for its original
 * (docs/14 §9.16, App\Actions\Backups\SwapRestoredService): it finished,
 * and the original and the copy are both still part of the environment.
 */
final class RestoreSwaps
{
    /**
     * @return array{ServiceInstance, ServiceInstance} the original and the restored copy
     */
    public static function pair(RestoreRun $run): array
    {
        if ($run->mode !== RestoreMode::NewService || $run->status !== RestoreRunStatus::Succeeded) {
            throw DomainRuleViolation::on('restore', 'Only a finished restore as a new service can be swapped in.');
        }

        $original = ServiceInstance::query()->whereKey($run->source_service_instance_id)->with(['service', 'environment'])->first();
        $copy = $run->target_service_instance_id === null ? null : ServiceInstance::query()->whereKey($run->target_service_instance_id)->with('service')->first();

        if (! $original instanceof ServiceInstance || $original->removed_at !== null) {
            throw DomainRuleViolation::conflict('The original service was already removed or swapped out.');
        }

        if (! $copy instanceof ServiceInstance || $copy->removed_at !== null || $copy->environment_id !== $original->environment_id) {
            throw DomainRuleViolation::conflict('The restored service no longer exists.');
        }

        return [$original, $copy];
    }

    public static function possible(RestoreRun $run): bool
    {
        try {
            self::pair($run);

            return true;
        } catch (DomainRuleViolation) {
            return false;
        }
    }
}
