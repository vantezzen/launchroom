<?php

declare(strict_types=1);

namespace App\Actions\Dockerfiles;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Services\UpdateServiceSettings;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Services\BuildSettings;
use App\Data\Services\UpdateServiceSettingsData;
use App\Enums\AuditAction;
use App\Enums\Dockerfiles\DockerfileGenerationStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Dockerfiles\DockerfileGeneration;
use App\Models\ServiceInstance;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "Use this Dockerfile" (docs/20 §5): only a verified generation; stages
 * `build.builder = dockerfile` with `build.generatedDockerfile` through the
 * normal settings action, so it is an ordinary staged rebuild.
 */
final readonly class UseGeneratedDockerfile
{
    public function __construct(
        private UpdateServiceSettings $settings,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(DockerfileGeneration $generation, User $user, AuditActor $actor): ServiceInstance
    {
        return DB::transaction(function () use ($generation, $user, $actor): ServiceInstance {
            $generation = DockerfileGeneration::query()->with(['serviceInstance', 'service'])->whereKey($generation->id)->lockForUpdate()->firstOrFail();

            if ($generation->status !== DockerfileGenerationStatus::Verified || $generation->dockerfile === null) {
                throw DomainRuleViolation::conflict('Only a Dockerfile that built and started can be used.');
            }

            if ($generation->serviceInstance->removed_at !== null) {
                throw DomainRuleViolation::conflict('This service is staged for removal.');
            }

            $current = $generation->serviceInstance->build;
            $build = BuildSettings::from([
                ...$current->toArray(),
                'builder' => 'dockerfile',
                'dockerfilePath' => null,
                'generatedDockerfile' => $generation->public_id,
            ]);

            $instance = $this->settings->handle($generation->serviceInstance, new UpdateServiceSettingsData(build: $build));

            $generation->forceFill(['accepted_at' => now(), 'accepted_by' => $user->id])->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::DockerfileGenerationUsed,
                actor: $actor,
                teamId: $generation->team_id,
                subjectType: 'service_instance',
                subjectId: $instance->public_id,
                meta: ['generation' => $generation->public_id, 'sha256' => hash('sha256', (string) $generation->dockerfile)],
            ));

            return $instance;
        });
    }
}
