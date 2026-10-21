<?php

declare(strict_types=1);

namespace App\Services\Dockerfiles;

use App\Data\Services\GitSource;
use App\Enums\ServiceKind;
use App\Models\Dockerfiles\AiProviderSettings;
use App\Models\ServiceInstance;
use App\Models\Team;

/**
 * Whether "Generate a Dockerfile" may run for a service (docs/20 §1, §3):
 * the instance switch with a key, the team switch, an app service with a
 * git source. Permissions are the policy's job; this is the opt-in.
 */
final class DockerfileGenerationGate
{
    public const string NOT_CONFIGURED = 'not_configured';

    public const string TEAM_OFF = 'team_off';

    public const string UNSUPPORTED = 'unsupported';

    /**
     * Null when available, else one of the reason constants.
     */
    public static function unavailableReason(Team $team, ?ServiceInstance $instance = null): ?string
    {
        $settings = AiProviderSettings::current();

        if (! $settings->enabled || ! self::modelConfigured($settings)) {
            return self::NOT_CONFIGURED;
        }

        if (! $team->settings->generateDockerfiles) {
            return self::TEAM_OFF;
        }

        if ($instance instanceof ServiceInstance) {
            $instance->loadMissing('service');

            if ($instance->service->kind !== ServiceKind::App || ! $instance->service->source instanceof GitSource) {
                return self::UNSUPPORTED;
            }
        }

        return null;
    }

    public static function modelConfigured(AiProviderSettings $settings): bool
    {
        // The stub (dev/tests) needs no key; the real provider does.
        return config('launchroom.dockerfile_generation.model') === 'stub' || $settings->hasKey();
    }
}
