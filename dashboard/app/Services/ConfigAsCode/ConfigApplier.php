<?php

declare(strict_types=1);

namespace App\Services\ConfigAsCode;

use App\Actions\Connections\CreateConnection;
use App\Actions\Domains\AddDomain;
use App\Actions\Domains\RemoveDomain;
use App\Actions\Services\AddServiceVolume;
use App\Actions\Services\CreateService;
use App\Actions\Services\RenameService;
use App\Actions\Services\UpdateServiceSettings;
use App\Data\Audit\AuditActor;
use App\Data\ConfigAsCode\ConfigFileProblemData;
use App\Data\ConfigAsCode\ManagedConfigData;
use App\Data\ConfigAsCode\ManagedServiceData;
use App\Data\Services\AddVolumeData;
use App\Data\Services\BuildSettings;
use App\Data\Services\CreateServiceData;
use App\Data\Services\DeploySettings;
use App\Data\Services\GitSource;
use App\Data\Services\ImageSource;
use App\Data\Services\RenameServiceData;
use App\Data\Services\ScalingSettings;
use App\Data\Services\UpdateServiceSettingsData;
use App\Enums\ConfigAsCode\ConfigTrust;
use App\Enums\ConnectionMode;
use App\Enums\ConnectionStyle;
use App\Enums\DomainKind;
use App\Enums\EnvironmentKind;
use App\Enums\ServiceKind;
use App\Enums\VariableType;
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
use App\Services\Variables\SecretExposureGuard;
use App\Services\Variables\VariableStore;
use App\Support\CanonicalJson;
use App\Support\ConfigAsCode\Arrays;
use App\Support\ConfigAsCode\ConfigFile;
use App\Support\ConfigAsCode\EffectiveService;
use App\Support\ConfigAsCode\Problems;
use App\Support\Hostname;
use App\Support\Variables\GeneratedValues;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Data;

/**
 * Writes a launchroom.json into an environment's intent (docs/18 §3.3)
 * through the same Actions and services as the dashboard, so the result is
 * ordinary staged changes. Runs inside the caller's transaction (which
 * holds the environment's lock and rolls back when this throws).
 *
 * The file is an overlay: only what it names is written. Services are never
 * removed, volumes and connections only added; variables and custom domains
 * the file managed before and no longer lists are removed. Trust rules for
 * previews and forks (docs/18 §5) are applied first and reported as ignored.
 */
final class ConfigApplier
{
    private const array ORDER = ['database' => 0, 'image' => 1, 'app' => 2, 'worker' => 3, 'cron' => 3];

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
        private readonly VariableStore $variables,
        private readonly EnvironmentIntentCopier $copier,
        private readonly SecretExposureGuard $exposure,
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
    public function apply(Environment $environment, ConfigFile $file, ConfigTrust $trust, ?GitSource $repository, AuditActor $actor, ?ManagedConfigData $previous = null, bool $dryRun = false): ConfigApplyResult
    {
        $this->problems = new Problems($file->document);
        $this->errors = [];
        $this->ignored = [];
        $this->domains = [];

        $environment->loadMissing('project');
        $project = $environment->project;
        $preview = $environment->kind === EnvironmentKind::Preview;
        $effective = $file->forEnvironment($environment->slug, $preview);
        uasort($effective, static fn (EffectiveService $a, EffectiveService $b): int => (self::ORDER[$a->kind()] ?? 9) <=> (self::ORDER[$b->kind()] ?? 9));

        $managed = [];
        /** @var array<string, Service> $services slug → service */
        $services = [];

        foreach ($effective as $slug => $service) {
            $config = $this->trusted($service, $trust);
            $result = $this->service($project, $environment, $service, $config, $trust, $repository, $actor, $previous, $dryRun);

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
            $maxCpus = config()->float('launchroom.previews.max_cpus');

            if (isset($scaling['placement'])) {
                unset($scaling['placement']);
                $this->ignored[] = $this->problems->at($service->pointerFor('scaling', 'placement'), "{$service->slug}: placement is ignored in previews (they run on servers that allow previews).", key: true);
            }

            if (is_int($scaling['replicas'] ?? null) && $scaling['replicas'] > 1) {
                $scaling['replicas'] = 1;
                $this->ignored[] = $this->problems->at($service->pointerFor('scaling', 'replicas'), "{$service->slug}: previews run 1 replica.");
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
    private function service(Project $project, Environment $environment, EffectiveService $effective, array $config, ConfigTrust $trust, ?GitSource $repository, AuditActor $actor, ?ManagedConfigData $previous, bool $dryRun): ?array
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
        $domains = $this->applyDomains($instance, $effective, $config, $previous, $actor, $dryRun);
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
            $instance->forceFill(['removed_at' => null])->save();

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
            ServiceKind::Image => ['type' => 'image', 'reference' => Arrays::str($config['image'] ?? null)],
            ServiceKind::Database => ['type' => 'database', 'engine' => Arrays::str($config['engine'] ?? null), 'version' => Arrays::str($config['version'] ?? null)],
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

    /**
     * @return array<string, mixed>
     */
    private function gitSource(?GitSource $repository, string $rootDirectory): array
    {
        if (! $repository instanceof GitSource) {
            throw DomainRuleViolation::on('source', 'a new app needs the repository that holds launchroom.json; add it on the canvas first.');
        }

        return ['type' => 'git', ...Arrays::assoc($repository->toArray()), 'rootDirectory' => $rootDirectory];
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
                    'image' => $service->forceFill(['source' => ImageSource::validateAndCreate(['reference' => $value])])->save(),
                    default => $service->forceFill(['source' => GitSource::from([...$source instanceof GitSource ? $source->toArray() : [], 'rootDirectory' => $value])])->save(),
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

        if (array_key_exists('branch', $config) && is_string($config['branch'])) {
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
            'build' => $kind === ServiceKind::App ? $this->group($config, 'build') : [],
            'deploy' => $kind === ServiceKind::Database ? [] : $this->group($config, 'deploy'),
            'scaling' => $this->scaling($this->group($config, 'scaling')),
        ];

        foreach ($groups as $group => $values) {
            if ($values === []) {
                continue;
            }

            foreach (self::leaves($values) as $leaf) {
                $fields[] = $group.'.'.$leaf;
            }

            /** @var Data $current */
            $current = $instance->{$group};
            $merged = $this->merge(Arrays::assoc($current->toArray()), $values);

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
        $changed = [];
        $target = [['environment_id' => $environment->id, 'all_previews' => false, 'branch_pattern' => null]];

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
                        $changed[] = $key;

                        continue;
                    }

                    if ($generator !== null || ! hash_equals($this->variables->plaintext($project, $existing), $value)) {
                        if ($generator !== null && $existing->type !== VariableType::Secret) {
                            $this->variables->updateMeta($existing, $key, VariableType::Secret, $existing->exposure_override);
                        }

                        $this->variables->updateValue($project, $existing, $value);
                        $changed[] = $key;
                    }

                    continue;
                }

                $this->variables->create($project, $service, $key, $value, $generator !== null ? VariableType::Secret : VariableType::Config, $target);
                $changed[] = $key;
            } catch (DomainRuleViolation $e) {
                $this->errors[] = $this->problems->at($pointer, "{$key}: {$e->getMessage()}");
            }
        }

        // Variables the file managed before and no longer lists are removed.
        foreach ($previous?->service($service->public_id)->variables ?? [] as $key) {
            if (! array_key_exists($key, $wanted) && ($existing = $this->copier->existing($environment, $service->id, $key)) instanceof Variable) {
                $this->copier->detach($existing, $environment);
            }
        }

        if ($changed !== []) {
            try {
                $this->exposure->assertNoPublicSecrets($project, $changed);
            } catch (DomainRuleViolation $e) {
                $this->errors[] = $this->problems->at($effective->pointerFor('variables'), "{$service->slug}: {$e->getMessage()}", key: true);
            }
        }

        $keys = array_map(strval(...), array_keys($wanted));
        sort($keys);

        return $keys;
    }

    /**
     * Custom domains apply right away, like in the dashboard. A dry run only
     * describes them.
     *
     * @param  array<string, mixed>  $config
     * @return list<string> managed hostnames
     */
    private function applyDomains(ServiceInstance $instance, EffectiveService $effective, array $config, ?ManagedConfigData $previous, AuditActor $actor, bool $dryRun): array
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

                if (! $dryRun) {
                    $this->removeDomain->handle($current[$hostname], $actor);
                }
            }
        }

        foreach ($wanted as $hostname => [$redirect, $pointer]) {
            $existing = $current[$hostname] ?? null;

            if ($existing instanceof Domain && $existing->redirect_to === $redirect) {
                continue;
            }

            $this->domains[] = ['service' => $slug, 'hostname' => $hostname, 'kind' => 'added'];

            if ($dryRun) {
                if (! $existing instanceof Domain && Domain::query()->where('hostname', $hostname)->exists()) {
                    $this->errors[] = $this->problems->at($pointer, "{$hostname} is already used by another service.");
                }

                continue;
            }

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

    private function line(string $pointer): int
    {
        return $this->problems->at($pointer, '', key: true)->line ?? 1;
    }

    /**
     * File scaling ({placement: {labels, strategy}}) in ScalingSettings' names.
     *
     * @param  array<string, mixed>  $scaling
     * @return array<string, mixed>
     */
    private function scaling(array $scaling): array
    {
        $placement = is_array($scaling['placement'] ?? null) ? $scaling['placement'] : [];
        unset($scaling['placement']);

        if (array_key_exists('labels', $placement)) {
            $scaling['placementLabels'] = $placement['labels'];
        }

        if (array_key_exists('strategy', $placement)) {
            $scaling['strategy'] = $placement['strategy'];
        }

        return $scaling;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function group(array $config, string $key): array
    {
        return Arrays::assoc($config[$key] ?? null);
    }

    /**
     * Dotted leaf paths of a settings group (`healthcheck.path`); lists and
     * label maps count as one leaf.
     *
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    private static function leaves(array $values, string $prefix = ''): array
    {
        $out = [];

        foreach ($values as $key => $value) {
            $key = $key === 'placementLabels' ? 'placement.labels' : ($key === 'strategy' ? 'placement.strategy' : (string) $key);

            if ($key === 'healthcheck' && is_array($value)) {
                array_push($out, ...self::leaves(Arrays::assoc($value), $prefix.'healthcheck.'));

                continue;
            }

            $out[] = $prefix.$key;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function merge(array $current, array $values): array
    {
        foreach ($values as $key => $value) {
            $current[$key] = $key === 'healthcheck' && is_array($value) ? [...Arrays::assoc($current[$key] ?? null), ...Arrays::assoc($value)] : $value;
        }

        return $current;
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
