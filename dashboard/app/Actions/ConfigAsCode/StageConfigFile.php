<?php

declare(strict_types=1);

namespace App\Actions\ConfigAsCode;

use App\Actions\Connections\CreateConnection;
use App\Actions\Domains\AddDomain;
use App\Actions\Domains\RemoveDomain;
use App\Actions\Services\AddServiceVolume;
use App\Actions\Services\CancelServiceRemoval;
use App\Actions\Services\ChangeRootDirectory;
use App\Actions\Services\ChangeServiceImage;
use App\Actions\Services\CreateService;
use App\Actions\Services\RenameService;
use App\Actions\Services\UpdateServiceSettings;
use App\Data\Audit\AuditActor;
use App\Data\ConfigAsCode\ConfigApplyResult;
use App\Data\ConfigAsCode\ConfigFileProblemData;
use App\Data\ConfigAsCode\ManagedConfigData;
use App\Data\ConfigAsCode\ManagedServiceData;
use App\Data\Services\AddVolumeData;
use App\Data\Services\BuildSettings;
use App\Data\Services\ChangeImageData;
use App\Data\Services\CreateServiceData;
use App\Data\Services\DeploySettings;
use App\Data\Services\GitSource;
use App\Data\Services\ImageSource;
use App\Data\Services\RenameServiceData;
use App\Data\Services\ScalingSettings;
use App\Data\Services\ServiceSources;
use App\Data\Services\UpdateServiceSettingsData;
use App\Data\Variables\VariableTargetRow;
use App\Enums\ConfigAsCode\ConfigTrust;
use App\Enums\ConnectionMode;
use App\Enums\ConnectionStyle;
use App\Enums\DomainKind;
use App\Enums\EnvironmentKind;
use App\Enums\ServiceKind;
use App\Enums\ServiceSourceType;
use App\Enums\VariableType;
use App\Exceptions\ConfigApplyFailed;
use App\Exceptions\DomainRuleViolation;
use App\Models\Connection;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Variable;
use App\Services\Environments\EnvironmentIntentCopier;
use App\Services\Projects\ServiceProvisioner;
use App\Services\Variables\VariableStore;
use App\Support\CanonicalJson;
use App\Support\ConfigAsCode\Arrays;
use App\Support\ConfigAsCode\ConfigFile;
use App\Support\ConfigAsCode\EffectiveService;
use App\Support\ConfigAsCode\Problems;
use App\Support\ConfigAsCode\SettingsMap;
use App\Support\Hostname;
use App\Support\Previews\PreviewLimits;
use App\Support\Variables\GeneratedValues;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Data;

/**
 * Writes a launchroom.json into an environment's intent (docs/18 §3.3)
 * through the same Actions and services as the dashboard, so the result is
 * ordinary staged changes. Runs inside the caller's transaction (which
 * holds the environment's lock and rolls back when this throws). Every
 * effect outside the database waits for the commit, so a dry run is this in
 * a transaction that is rolled back (DryRunConfigFile).
 *
 * The file is an overlay: only what it names is written. Services are never
 * removed, volumes and connections only added; variables and custom domains
 * the file managed before and no longer lists are removed. Trust rules for
 * previews and forks (docs/18 §5) are applied first and reported as ignored.
 */
final class StageConfigFile
{
    private Problems $problems;

    /** @var list<ConfigFileProblemData> */
    private array $errors = [];

    /** @var list<ConfigFileProblemData> */
    private array $ignored = [];

    /** @var list<array{service: string, hostname: string, kind: 'added'|'removed'}> */
    private array $domains = [];

    public function __construct(
        private readonly CreateService $createService,
        private readonly ServiceProvisioner $provisioner,
        private readonly UpdateServiceSettings $updateSettings,
        private readonly RenameService $rename,
        private readonly ChangeServiceImage $changeImage,
        private readonly ChangeRootDirectory $changeRootDirectory,
        private readonly CancelServiceRemoval $cancelRemoval,
        private readonly VariableStore $variables,
        private readonly EnvironmentIntentCopier $copier,
        private readonly AddDomain $addDomain,
        private readonly RemoveDomain $removeDomain,
        private readonly AddServiceVolume $addVolume,
        private readonly CreateConnection $connect,
    ) {}

    /**
     * @param  GitSource|null  $repository  the repository holding the file (source of new app services)
     *
     * @throws ConfigApplyFailed
     */
    public function handle(Environment $environment, ConfigFile $file, ConfigTrust $trust, ?GitSource $repository, AuditActor $actor, ?ManagedConfigData $previous = null): ConfigApplyResult
    {
        $this->problems = new Problems($file->document);
        $this->errors = [];
        $this->ignored = [];
        $this->domains = [];

        $environment->loadMissing('project');
        $project = $environment->project;
        $preview = $environment->kind === EnvironmentKind::Preview;
        $effective = $file->forEnvironment($environment->slug, $preview);
        uasort($effective, static fn (EffectiveService $a, EffectiveService $b): int => self::order($a) <=> self::order($b));

        $managed = [];
        /** @var array<string, Service> $services slug → service */
        $services = [];

        foreach ($effective as $slug => $service) {
            $config = $this->trusted($service, $trust);
            $result = $this->service($project, $environment, $service, $config, $trust, $repository, $actor, $previous);

            if ($result !== null) {
                [$model, $entry] = $result;
                $services[$slug] = $model;
                $managed[$model->public_id] = $entry;
            }
        }

        if ($trust !== ConfigTrust::Fork) {
            foreach ($effective as $slug => $service) {
                if (isset($services[$slug])) {
                    $this->connections($project, $services[$slug], $service, $actor);
                }
            }
        }

        if ($this->errors !== []) {
            throw new ConfigApplyFailed($this->errors);
        }

        ksort($managed);

        return new ConfigApplyResult(new ManagedConfigData($managed), $this->ignored, $this->domains);
    }

    /**
     * The service's settings after the trust rules (docs/18 §5).
     *
     * @return array<string, mixed>
     */
    private function trusted(EffectiveService $service, ConfigTrust $trust): array
    {
        $config = $service->config;

        if (! $trust->isPreview()) {
            return $config;
        }

        $ignore = function (string $key, string $why) use (&$config, $service): void {
            if (array_key_exists($key, $config)) {
                unset($config[$key]);
                $this->ignored[] = $this->problems->at($service->pointerFor($key), $why, key: true);
            }
        };

        $ignore('domains', "{$service->slug}: domains are ignored in previews (previews get their own addresses).");
        $ignore('enabled', "{$service->slug}: enabled is ignored in previews.");
        $ignore('branch', "{$service->slug}: branch is ignored in previews (they deploy the pull request).");

        if ($trust === ConfigTrust::Fork) {
            $ignore('connections', "{$service->slug}: connections are ignored for pull requests from forks.");
            $ignore('volumes', "{$service->slug}: volumes are ignored for pull requests from forks.");
        }

        if (is_array($config['scaling'] ?? null)) {
            $scaling = $config['scaling'];
            $maxCpus = PreviewLimits::cpus();
            $replicas = PreviewLimits::REPLICAS;

            if (isset($scaling['placement'])) {
                unset($scaling['placement']);
                $this->ignored[] = $this->problems->at($service->pointerFor('scaling', 'placement'), "{$service->slug}: placement is ignored in previews (they run on servers that allow previews).", key: true);
            }

            if (is_int($scaling['replicas'] ?? null) && $scaling['replicas'] > $replicas) {
                $scaling['replicas'] = $replicas;
                $this->ignored[] = $this->problems->at($service->pointerFor('scaling', 'replicas'), "{$service->slug}: previews run {$replicas} replica.");
            }

            if (is_numeric($scaling['cpus'] ?? null) && (float) $scaling['cpus'] > $maxCpus) {
                $scaling['cpus'] = $maxCpus;
                $this->ignored[] = $this->problems->at($service->pointerFor('scaling', 'cpus'), "{$service->slug}: previews get at most {$maxCpus} CPU.");
            }

            $config['scaling'] = $scaling;
        }

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{Service, ManagedServiceData}|null
     */
    private function service(Project $project, Environment $environment, EffectiveService $effective, array $config, ConfigTrust $trust, ?GitSource $repository, AuditActor $actor, ?ManagedConfigData $previous): ?array
    {
        $slug = $effective->slug;
        $kind = ServiceKind::from($effective->kind());
        $service = Service::query()->where('project_id', $project->id)->where('slug', $slug)->first();
        $instance = $service === null ? null : ServiceInstance::query()->where('environment_id', $environment->id)->where('service_id', $service->id)->first();
        $fields = [];
        $created = false;

        try {
            if (! $service instanceof Service || ! $instance instanceof ServiceInstance || $instance->removed_at !== null) {
                if ($trust === ConfigTrust::Fork) {
                    $this->ignored[] = $this->problems->at($effective->pointer, "{$slug}: new services are ignored for pull requests from forks.", key: true);

                    return null;
                }

                [$service, $instance, $created] = $this->ensure($project, $environment, $service, $instance, $effective, $config, $kind, $repository, $actor);
            }
        } catch (DomainRuleViolation $e) {
            $this->errors[] = $this->problems->at($effective->pointer, "{$slug}: {$e->getMessage()}", key: true);

            return null;
        }

        $fields = [...$fields, ...$this->identity($environment, $service, $effective, $config, $trust, $created, $actor)];
        $fields = [...$fields, ...$this->settings($instance, $effective, $config, $kind)];
        $variables = $this->applyVariables($project, $environment, $service, $effective, $config, $trust, $previous);
        $domains = $this->applyDomains($instance, $effective, $config, $previous, $actor);
        $this->applyVolumes($instance, $effective, $config, $actor);

        foreach (['volumes', 'connections'] as $key) {
            if (array_key_exists($key, $config)) {
                $fields[] = $key;
            }
        }

        sort($fields);

        return [$service, new ManagedServiceData($slug, $this->line($effective->pointer), array_values(array_unique($fields)), $variables, $domains)];
    }

    /**
     * Creates the service (or its instance in this environment).
     *
     * @param  array<string, mixed>  $config
     * @return array{Service, ServiceInstance, bool}
     */
    private function ensure(Project $project, Environment $environment, ?Service $service, ?ServiceInstance $instance, EffectiveService $effective, array $config, ServiceKind $kind, ?GitSource $repository, AuditActor $actor): array
    {
        if ($instance instanceof ServiceInstance && $service instanceof Service) {
            // Staged for removal in the dashboard, but the file lists it: it stays.
            $this->cancelRemoval->handle($instance);

            return [$service, $instance, false];
        }

        if ($service instanceof Service) {
            $parent = $service->parent_service_id === null ? null : ServiceInstance::query()->current()->where('environment_id', $environment->id)->where('service_id', $service->parent_service_id)->exists();

            if ($parent === false) {
                throw DomainRuleViolation::on('of', 'its app isn\'t in this environment.');
            }

            $environment->setRelation('project', $project);

            return [$service, $this->provisioner->provision($service, $environment), false];
        }

        $deploy = is_array($config['deploy'] ?? null) ? $config['deploy'] : [];
        $source = match ($kind) {
            ServiceKind::App => $this->gitSource($repository, is_string($config['rootDirectory'] ?? null) ? $config['rootDirectory'] : ''),
            ServiceKind::Image => ServiceSources::validated(['type' => ServiceSourceType::Image->value, 'reference' => Arrays::str($config['image'] ?? null)]),
            ServiceKind::Database => ServiceSources::validated(['type' => ServiceSourceType::Database->value, 'engine' => Arrays::str($config['engine'] ?? null), 'version' => Arrays::str($config['version'] ?? null)]),
            default => null,
        };
        $parentId = null;

        if ($kind->requiresParent()) {
            $parentId = Service::query()->where('project_id', $project->id)->where('slug', Arrays::str($config['of'] ?? null))->value('public_id');
        }

        $created = $this->createService->handle($project, new CreateServiceData(
            name: is_string($config['name'] ?? null) ? $config['name'] : $effective->slug,
            kind: $kind,
            source: $source,
            slug: $effective->slug,
            parentServiceId: is_string($parentId) ? $parentId : null,
            port: is_int($deploy['port'] ?? null) ? $deploy['port'] : null,
            startCommand: is_array($deploy['startCommand'] ?? null) ? array_values(array_map(Arrays::str(...), $deploy['startCommand'])) : null,
        ), null, $actor, $environment);

        $instance = ServiceInstance::query()->where('environment_id', $environment->id)->where('service_id', $created->id)->firstOrFail();

        return [$created, $instance, true];
    }

    private function gitSource(?GitSource $repository, string $rootDirectory): GitSource
    {
        if (! $repository instanceof GitSource) {
            throw DomainRuleViolation::on('source', 'a new app needs the repository that holds launchroom.json; add it on the canvas first.');
        }

        return GitSource::from([...$repository->toArray(), 'rootDirectory' => $rootDirectory]);
    }

    /**
     * Name, image and root directory are shared by every environment: only
     * production's file changes them (docs/18 §3.3).
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function identity(Environment $environment, Service $service, EffectiveService $effective, array $config, ConfigTrust $trust, bool $created, AuditActor $actor): array
    {
        $fields = [];
        $source = $service->source;
        $wanted = [
            'name' => is_string($config['name'] ?? null) ? $config['name'] : null,
            'image' => is_string($config['image'] ?? null) && $source instanceof ImageSource ? $config['image'] : null,
            'rootDirectory' => is_string($config['rootDirectory'] ?? null) && $source instanceof GitSource && $service->kind === ServiceKind::App ? $config['rootDirectory'] : null,
        ];
        $current = [
            'name' => $service->name,
            'image' => $source instanceof ImageSource ? $source->reference : null,
            'rootDirectory' => $source instanceof GitSource ? $source->rootDirectory : null,
        ];

        foreach ($wanted as $key => $value) {
            if ($value === null) {
                continue;
            }

            if ($created || $value === $current[$key]) {
                $fields[] = $key;

                continue;
            }

            if ($trust->isPreview()) {
                $this->ignored[] = $this->problems->at($effective->pointerFor($key), "{$effective->slug}: {$key} is ignored in previews.", key: true);

                continue;
            }

            if ($environment->kind !== EnvironmentKind::Production) {
                $this->ignored[] = $this->problems->at($effective->pointerFor($key), "{$effective->slug}: {$key} is shared by every environment and follows production's launchroom.json.", key: true);

                continue;
            }

            try {
                match ($key) {
                    'name' => $this->rename->handle($service, new RenameServiceData($value), $actor),
                    'image' => $this->changeImage->handle($service, ChangeImageData::validateAndCreate(['image' => $value]), $actor),
                    default => $this->changeRootDirectory->handle($service, $value),
                };
                $fields[] = $key;
            } catch (ValidationException|DomainRuleViolation $e) {
                $this->errors[] = $this->problems->at($effective->pointerFor($key), "{$effective->slug}: ".$this->message($e));
            }
        }

        return $fields;
    }

    /**
     * Branch, enabled, build, deploy and scaling through UpdateServiceSettings
     * (its rules: placement constraints, one replica for databases).
     *
     * @param  array<string, mixed>  $config
     * @return list<string> managed setting paths
     */
    private function settings(ServiceInstance $instance, EffectiveService $effective, array $config, ServiceKind $kind): array
    {
        $fields = [];
        $update = [];

        if ($kind->hasConfigField('branch') && array_key_exists('branch', $config) && is_string($config['branch'])) {
            $fields[] = 'branch';

            if ($config['branch'] !== $instance->branch_override) {
                $update['branchOverride'] = $config['branch'];
            }
        }

        if (is_bool($config['enabled'] ?? null)) {
            $fields[] = 'enabled';

            if ($config['enabled'] !== $instance->enabled) {
                $update['enabled'] = $config['enabled'];
            }
        }

        $groups = [
            'build' => $kind->hasConfigField('build') ? $this->group($config, 'build') : [],
            'deploy' => $kind->hasConfigField('deploy') ? $this->group($config, 'deploy') : [],
            'scaling' => SettingsMap::scalingFromFile($this->group($config, 'scaling')),
        ];

        foreach ($groups as $group => $values) {
            if ($values === []) {
                continue;
            }

            foreach (SettingsMap::leaves($values) as $leaf) {
                $fields[] = $group.'.'.$leaf;
            }

            /** @var Data $current */
            $current = $instance->{$group};
            $merged = SettingsMap::merge(Arrays::assoc($current->toArray()), $values);

            try {
                // The schema checked every value the file sets; the merged rest is stored settings.
                $next = match ($group) {
                    'build' => BuildSettings::from($merged),
                    'deploy' => DeploySettings::from($merged),
                    default => ScalingSettings::from($merged),
                };
            } catch (ValidationException $e) {
                $this->errors[] = $this->problems->at($effective->pointerFor($group), "{$effective->slug}: ".$this->message($e));

                continue;
            }

            if (CanonicalJson::encode($next->toArray()) !== CanonicalJson::encode($current->toArray())) {
                $update[$group] = $next;
            }
        }

        if ($update !== []) {
            try {
                $this->updateSettings->handle($instance, UpdateServiceSettingsData::from($update));
                $instance->refresh();
            } catch (DomainRuleViolation $e) {
                $field = explode('.', $e->field)[0];
                $this->errors[] = $this->problems->at($effective->pointerFor(in_array($field, ['build', 'deploy', 'scaling'], true) ? $field : 'scaling'), "{$effective->slug}: {$e->getMessage()}");
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string> managed keys
     */
    private function applyVariables(Project $project, Environment $environment, Service $service, EffectiveService $effective, array $config, ConfigTrust $trust, ?ManagedConfigData $previous): array
    {
        $wanted = $this->group($config, 'variables');
        $target = [VariableTargetRow::environment($environment->id)];

        try {
            $this->variables->write($project, function () use ($project, $environment, $service, $effective, $wanted, $trust, $target): void {
                $this->writeVariables($project, $environment, $service, $effective, $wanted, $trust, $target);
            });
        } catch (DomainRuleViolation $e) {
            $this->errors[] = $this->problems->at($effective->pointerFor('variables'), "{$service->slug}: {$e->getMessage()}", key: true);
        }

        // Variables the file managed before and no longer lists are removed.
        foreach ($previous?->service($service->public_id)->variables ?? [] as $key) {
            if (! array_key_exists($key, $wanted) && ($existing = $this->copier->existing($environment, $service->id, $key)) instanceof Variable) {
                $this->copier->detach($existing, $environment);
            }
        }

        $keys = array_map(strval(...), array_keys($wanted));
        sort($keys);

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $wanted
     * @param  list<VariableTargetRow>  $target
     */
    private function writeVariables(Project $project, Environment $environment, Service $service, EffectiveService $effective, array $wanted, ConfigTrust $trust, array $target): void
    {
        foreach ($wanted as $key => $value) {
            $key = (string) $key;
            $pointer = $effective->pointerFor('variables', $key);
            $existing = $this->copier->existing($environment, $service->id, $key);

            try {
                if (is_array($value)) {
                    if ($trust->isPreview()) {
                        continue;
                    }

                    if (! $existing instanceof Variable) {
                        $description = is_string($value['description'] ?? null) ? ' ('.$value['description'].')' : '';
                        $this->errors[] = $this->problems->at($pointer, "{$service->slug} needs the secret {$key}{$description}, but it isn't set in {$environment->name}.", "Add it in Variables, or run `launchroom env set --secret {$key}=…` for this environment.", key: true);
                    } elseif ($existing->type !== VariableType::Secret) {
                        $this->errors[] = $this->problems->at($pointer, "{$key} is a config value in {$environment->name}, but launchroom.json says it's a secret.", 'Change it to a secret in Variables, or write its value here if it isn\'t secret.', key: true);
                    }

                    continue;
                }

                $value = Arrays::str($value);
                $generator = GeneratedValues::sole($value);

                if ($existing instanceof Variable) {
                    if ($generator !== null && $existing->generator === $generator) {
                        continue;
                    }

                    if ($existing->type === VariableType::Secret && $generator === null) {
                        $this->errors[] = $this->problems->at($pointer, "{$key} is a secret in {$environment->name}; launchroom.json can't set its value.", "Write \"{$key}\": { \"secret\": true } and keep the value in the dashboard.", key: true);

                        continue;
                    }

                    if ($existing->targets()->count() > 1) {
                        // Shared with other environments: this one gets its own row.
                        $this->copier->detach($existing, $environment);
                        $this->variables->create($project, $service, $key, $value, $generator !== null ? VariableType::Secret : $existing->type, $target, $existing->exposure_override);

                        continue;
                    }

                    if ($generator !== null || ! hash_equals($this->variables->plaintext($project, $existing), $value)) {
                        if ($generator !== null && $existing->type !== VariableType::Secret) {
                            $this->variables->updateMeta($existing, $key, VariableType::Secret, $existing->exposure_override);
                        }

                        $this->variables->updateValue($project, $existing, $value);
                    }

                    continue;
                }

                $this->variables->create($project, $service, $key, $value, $generator !== null ? VariableType::Secret : VariableType::Config, $target);
            } catch (DomainRuleViolation $e) {
                $this->errors[] = $this->problems->at($pointer, "{$key}: {$e->getMessage()}");
            }
        }
    }

    /**
     * Custom domains apply right away, like in the dashboard.
     *
     * @param  array<string, mixed>  $config
     * @return list<string> managed hostnames
     */
    private function applyDomains(ServiceInstance $instance, EffectiveService $effective, array $config, ?ManagedConfigData $previous, AuditActor $actor): array
    {
        $wanted = [];

        foreach (is_array($config['domains'] ?? null) ? $config['domains'] : [] as $index => $domain) {
            $hostname = Hostname::normalize(Arrays::str(is_array($domain) ? ($domain['hostname'] ?? null) : $domain));
            $redirect = is_array($domain) && is_string($domain['redirectTo'] ?? null) ? Hostname::normalize($domain['redirectTo']) : null;
            $wanted[$hostname] = [$redirect, $effective->pointerFor('domains', (string) $index)];
        }

        $managedBefore = $previous?->service($instance->service->public_id)->domains ?? [];

        if ($wanted === [] && $managedBefore === []) {
            return [];
        }

        /** @var array<string, Domain> $current */
        $current = Domain::query()->where('service_instance_id', $instance->id)->where('kind', DomainKind::Custom->value)->get()->keyBy('hostname')->all();
        $slug = $instance->service->slug;

        foreach ($managedBefore as $hostname) {
            if (! isset($wanted[$hostname]) && isset($current[$hostname])) {
                $this->domains[] = ['service' => $slug, 'hostname' => $hostname, 'kind' => 'removed'];
                $this->removeDomain->handle($current[$hostname], $actor);
            }
        }

        foreach ($wanted as $hostname => [$redirect, $pointer]) {
            $existing = $current[$hostname] ?? null;

            if ($existing instanceof Domain && $existing->redirect_to === $redirect) {
                continue;
            }

            $this->domains[] = ['service' => $slug, 'hostname' => $hostname, 'kind' => 'added'];

            try {
                if ($existing instanceof Domain) {
                    $this->removeDomain->handle($existing, $actor);
                }

                $this->addDomain->handle($instance, $hostname, $actor, $redirect);
            } catch (DomainRuleViolation $e) {
                $this->errors[] = $this->problems->at($pointer, "{$hostname}: {$e->getMessage()}");
            }
        }

        $hostnames = array_map(strval(...), array_keys($wanted));
        sort($hostnames);

        return $hostnames;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function applyVolumes(ServiceInstance $instance, EffectiveService $effective, array $config, AuditActor $actor): void
    {
        foreach (is_array($config['volumes'] ?? null) ? $config['volumes'] : [] as $index => $path) {
            $path = Arrays::str($path);

            if ($instance->volumes()->where('mount_path', $path)->exists()) {
                continue;
            }

            try {
                $this->addVolume->handle($instance, new AddVolumeData($path), $actor);
            } catch (DomainRuleViolation $e) {
                $this->errors[] = $this->problems->at($effective->pointerFor('volumes', (string) $index), "{$path}: {$e->getMessage()}");
            }
        }
    }

    private function connections(Project $project, Service $from, EffectiveService $effective, AuditActor $actor): void
    {
        foreach (is_array($effective->config['connections'] ?? null) ? $effective->config['connections'] : [] as $index => $connection) {
            $toSlug = Arrays::str(is_array($connection) ? ($connection['to'] ?? null) : $connection);
            $to = Service::query()->where('project_id', $project->id)->where('slug', $toSlug)->first();

            if (! $to instanceof Service || Connection::query()->where('from_service_id', $from->id)->where('to_service_id', $to->id)->exists()) {
                continue;
            }

            try {
                $this->connect->handle(
                    $from,
                    $to,
                    ConnectionMode::from(is_array($connection) && is_string($connection['mode'] ?? null) ? $connection['mode'] : 'private'),
                    ConnectionStyle::from(is_array($connection) && is_string($connection['style'] ?? null) ? $connection['style'] : 'url'),
                    null,
                    $actor,
                );
            } catch (DomainRuleViolation $e) {
                $this->errors[] = $this->problems->at($effective->pointerFor('connections', (string) $index), "{$from->slug} → {$toSlug}: {$e->getMessage()}");
            }
        }
    }

    /**
     * Databases first, then images and apps, then what runs an app's code:
     * a service's connections and parent exist before it.
     */
    private static function order(EffectiveService $service): int
    {
        return match (ServiceKind::tryFrom($service->kind())) {
            ServiceKind::Database => 0,
            ServiceKind::Image => 1,
            ServiceKind::App => 2,
            ServiceKind::Worker, ServiceKind::Cron => 3,
            default => 9,
        };
    }

    private function line(string $pointer): int
    {
        return $this->problems->at($pointer, '', key: true)->line ?? 1;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function group(array $config, string $key): array
    {
        return Arrays::assoc($config[$key] ?? null);
    }

    private function message(ValidationException|DomainRuleViolation $e): string
    {
        if ($e instanceof ValidationException) {
            $first = array_values($e->errors())[0] ?? [];

            return is_array($first) && is_string($first[0] ?? null) ? $first[0] : $e->getMessage();
        }

        return $e->getMessage();
    }
}
