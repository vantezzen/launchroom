<?php

declare(strict_types=1);

namespace App\Actions\Dockerfiles;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Dockerfiles\UpdateAiProviderData;
use App\Enums\AuditAction;
use App\Exceptions\DomainRuleViolation;
use App\Models\Dockerfiles\AiProviderSettings;
use App\Models\User;
use App\Services\Dockerfiles\AiProviderSecrets;
use App\Services\Dockerfiles\DockerfileGenerationGate;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelData\Optional;

/**
 * Settings → Dockerfile generation, the instance part (docs/20 §1):
 * on/off, model, API key (set, replace, remove). Audited without the key.
 */
final readonly class UpdateAiProviderSettings
{
    public function __construct(
        private AiProviderSecrets $secrets,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(UpdateAiProviderData $data, User $user, AuditActor $actor): AiProviderSettings
    {
        return DB::transaction(function () use ($data, $user, $actor): AiProviderSettings {
            $settings = AiProviderSettings::lockedForUpdate();
            $changes = [];

            if (! $data->apiKey instanceof Optional && $data->apiKey !== null && $data->apiKey !== '') {
                $this->secrets->put($settings, $data->apiKey);
                $changes[] = 'key_set';
            }

            if ($data->removeKey) {
                $this->secrets->forget($settings);
                $changes[] = 'key_removed';
            }

            if (! $data->model instanceof Optional && $data->model !== $settings->model) {
                $settings->model = $data->model === config('launchroom.dockerfile_generation.default_model') ? null : $data->model;
                $changes[] = 'model';
            }

            if (! $data->enabled instanceof Optional && $data->enabled !== $settings->enabled) {
                $settings->enabled = $data->enabled;
                $changes[] = $data->enabled ? 'enabled' : 'disabled';
            }

            if ($settings->enabled && ! DockerfileGenerationGate::modelConfigured($settings)) {
                throw DomainRuleViolation::on('apiKey', 'Add an API key before turning Dockerfile generation on.');
            }

            if ($changes === []) {
                return $settings;
            }

            $settings->updated_by = $user->id;
            $settings->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::AiProviderUpdated,
                actor: $actor,
                subjectType: 'ai_provider',
                subjectId: $settings->provider->value,
                meta: ['changes' => $changes, 'model' => $settings->effectiveModel(), 'enabled' => $settings->enabled],
            ));

            return $settings;
        });
    }
}
