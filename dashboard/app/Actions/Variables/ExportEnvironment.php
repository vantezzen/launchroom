<?php

declare(strict_types=1);

namespace App\Actions\Variables;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Api\ResolvedEnvData;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Specs\ServiceSpec;
use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\EnvironmentRevision;
use App\Models\Service;
use App\Models\User;
use App\Queries\CurrentEnvironmentSpec;
use App\Services\Variables\SpecVariableResolver;
use App\Support\Variables\SecretTaint;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * The resolved env of a service for `env pull` and `run` (docs/16 §3.3):
 * the same resolver as deploys, over the last deployed revision (the
 * current intent before the first deploy). Secrets and config values that
 * embed one (secret taint, docs/09 R16a) are included only when asked for
 * and the user may reveal secrets (∩ the token's abilities); otherwise
 * their keys are listed as withheld. Every export is audited, never with
 * values.
 */
final readonly class ExportEnvironment
{
    public function __construct(
        private CurrentEnvironmentSpec $currentSpec,
        private SpecVariableResolver $resolver,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Environment $environment, Service $service, User $user, bool $withSecrets, AuditActor $actor): ResolvedEnvData
    {
        $environment->loadMissing(['project', 'lastAppliedRevision']);
        $revision = $environment->lastAppliedRevision;
        $spec = $revision instanceof EnvironmentRevision ? $revision->spec : $this->currentSpec->get($environment);
        $serviceSpec = $spec->service($service->public_id);

        if (! $serviceSpec instanceof ServiceSpec) {
            throw (new ModelNotFoundException)->setModel(Service::class);
        }

        $values = $this->resolver->resolve($environment->project, $spec, $serviceSpec)->all();
        $tainted = SecretTaint::fromSpec($spec)->taintedKeys($serviceSpec->slug);
        $includeSecrets = $withSecrets && $user->hasTeamPermission($environment->team_id, Permission::RevealSecrets);
        $withheld = [];

        if (! $includeSecrets) {
            foreach ($tainted as $key) {
                if (array_key_exists($key, $values)) {
                    unset($values[$key]);
                    $withheld[] = $key;
                }
            }
        }

        ksort($values);

        DB::transaction(fn (): AuditEvent => $this->audit->handle(new AuditEntryData(
            action: AuditAction::EnvExported,
            actor: $actor,
            teamId: $environment->team_id,
            subjectType: 'service',
            subjectId: $service->public_id,
            meta: [
                'environment' => $environment->public_id,
                'keys' => count($values),
                'with_secrets' => $includeSecrets && $tainted !== [],
                'revision' => $revision?->number,
            ],
        )));

        return new ResolvedEnvData($values, $withheld, $revision?->number);
    }
}
