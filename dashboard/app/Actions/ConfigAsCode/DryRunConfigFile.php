<?php

declare(strict_types=1);

namespace App\Actions\ConfigAsCode;

use App\Data\Audit\AuditActor;
use App\Data\ConfigAsCode\ConfigCheckData;
use App\Data\Specs\ChangeItem;
use App\Enums\ChangeEffect;
use App\Enums\ChangeKind;
use App\Enums\ConfigAsCode\ConfigTrust;
use App\Enums\EnvironmentKind;
use App\Enums\PromotionClass;
use App\Models\ConfigAsCode\EnvironmentConfigFile;
use App\Models\Environment;
use App\Queries\CurrentEnvironmentSpec;
use App\Services\ConfigAsCode\ConfigApplier;
use App\Services\ConfigAsCode\ConfigApplyFailed;
use App\Services\ConfigAsCode\ConfigFileReader;
use App\Support\Changes\SpecDiff;
use App\Support\ConfigAsCode\ConfigContext;
use App\Support\ConfigAsCode\ConfigFile;
use App\Support\ConfigAsCode\ConfigFileValidator;
use Illuminate\Support\Facades\DB;

/**
 * `config validate` / `config diff` (docs/18 §7): checks a launchroom.json
 * against an environment and, with $diff, applies it inside a transaction
 * that is always rolled back to list the staged changes it would produce.
 * Domains are only described (they would apply at once), nothing is audited.
 */
final readonly class DryRunConfigFile
{
    public function __construct(
        private ConfigFileValidator $validator,
        private ConfigApplier $applier,
        private CurrentEnvironmentSpec $currentSpec,
        private ConfigFileReader $reader,
    ) {}

    public function handle(Environment $environment, string $content, bool $diff): ConfigCheckData
    {
        $environment->loadMissing('project');
        $check = $this->validator->check($content, ConfigContext::forProject($environment->project));

        if (! $check->valid() || ! $check->file instanceof ConfigFile) {
            return new ConfigCheckData(false, $check->errors, $check->warnings);
        }

        if (! $diff) {
            return new ConfigCheckData(true, warnings: $check->warnings);
        }

        DB::beginTransaction();

        try {
            $locked = Environment::query()->whereKey($environment->id)->with('project')->lockForUpdate()->firstOrFail();
            $before = $this->currentSpec->get($locked);
            $previous = EnvironmentConfigFile::query()->where('environment_id', $locked->id)->first()?->managed;
            $trust = $locked->kind === EnvironmentKind::Preview ? ($locked->secrets_withheld ? ConfigTrust::Fork : ConfigTrust::Preview) : ConfigTrust::Trusted;

            try {
                $result = $this->applier->apply($locked, $check->file, $trust, $this->reader->repository($locked->project), AuditActor::system(), $previous, dryRun: true);
            } catch (ConfigApplyFailed $e) {
                return new ConfigCheckData(false, $e->problems, $check->warnings);
            }

            $changes = SpecDiff::between($before, $this->currentSpec->get($locked))->items;

            foreach ($result->domains as $domain) {
                $service = $before->serviceBySlug($domain['service']);
                $changes[] = new ChangeItem(
                    path: 'services.'.($service->id ?? $domain['service']).'.domains.'.$domain['hostname'],
                    kind: $domain['kind'] === 'added' ? ChangeKind::Added : ChangeKind::Removed,
                    effect: ChangeEffect::Route,
                    promotion: PromotionClass::EnvironmentSpecific,
                    label: ($domain['kind'] === 'added' ? 'Domain ' : 'Remove domain ').$domain['hostname'].' (applies right away)',
                    serviceId: $service?->id,
                );
            }

            return new ConfigCheckData(true, [], $check->warnings, $result->ignored, $changes);
        } finally {
            DB::rollBack();
        }
    }
}
