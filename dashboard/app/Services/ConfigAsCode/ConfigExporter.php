<?php

declare(strict_types=1);

namespace App\Services\ConfigAsCode;

use App\Data\Services\BuildSettings;
use App\Data\Services\DatabaseSource;
use App\Data\Services\DeploySettings;
use App\Data\Services\GitSource;
use App\Data\Services\HealthcheckSettings;
use App\Data\Services\ImageSource;
use App\Data\Specs\EnvironmentSpec;
use App\Data\Specs\ServiceSpec;
use App\Data\Specs\VariableSpec;
use App\Data\Specs\VolumeSpec;
use App\Drivers\DatabaseEngines\DatabaseEngines;
use App\Drivers\DatabaseEngines\DriverVariable;
use App\Enums\ConnectionMode;
use App\Enums\ConnectionStyle;
use App\Enums\DomainKind;
use App\Enums\ServiceKind;
use App\Enums\VariableLayer;
use App\Enums\VariableType;
use App\Models\Project;
use App\Models\Variable;
use App\Services\Crypto\ProjectKeyring;
use App\Support\ConfigAsCode\Arrays;
use App\Support\ConfigAsCode\ConfigSchema;
use App\Support\ConfigAsCode\SettingsMap;

/**
 * An environment (its current intent or any revision) as launchroom.json
 * (docs/18 §4). Writes only what differs from what a new service of the
 * same kind would get, so the file stays short and imports back without a
 * change. Never a secret value: secrets become `{ "secret": true }`,
 * generated values their generator.
 */
final readonly class ConfigExporter
{
    public function __construct(
        private ProjectKeyring $keyring,
        private DatabaseEngines $engines,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function export(Project $project, EnvironmentSpec $spec): array
    {
        $slugs = [];

        foreach ($spec->services as $id => $service) {
            $slugs[$id] = $service->slug;
        }

        /** @var array<string, string> $generators variable public id → generator */
        $generators = Variable::query()
            ->where('project_id', $project->id)
            ->whereNotNull('generator')
            ->pluck('generator', 'public_id')
            ->all();

        $services = [];

        foreach ($spec->services as $service) {
            $services[$service->slug] = $this->service($project, $spec, $service, $slugs, $generators);
        }

        ksort($services);

        return [
            '$schema' => ConfigSchema::ID,
            'version' => 1,
            'services' => $services === [] ? new \stdClass : $services,
        ];
    }

    /**
     * @param  array<string, mixed>  $file
     */
    public static function encode(array $file): string
    {
        $json = json_encode($file, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        // Two-space indentation, as most JSON in repositories.
        return preg_replace_callback('/^( {4})+/m', static fn (array $m): string => str_repeat('  ', intdiv(strlen($m[0]), 4)), $json)."\n";
    }

    /**
     * @param  array<string, string>  $slugs
     * @param  array<string, string>  $generators
     * @return array<string, mixed>
     */
    private function service(Project $project, EnvironmentSpec $spec, ServiceSpec $service, array $slugs, array $generators): array
    {
        $source = $service->source();
        $out = ['kind' => $service->kind->value];

        if ($service->name !== $service->slug) {
            $out['name'] = $service->name;
        }

        if ($service->kind === ServiceKind::App && $source instanceof GitSource && $source->rootDirectory !== '') {
            $out['rootDirectory'] = $source->rootDirectory;
        }

        if ($source instanceof ImageSource && $service->kind === ServiceKind::Image) {
            $out['image'] = $source->reference;
        }

        if ($source instanceof DatabaseSource) {
            $out['engine'] = $source->engine->value;
            $out['version'] = $source->version;
        }

        if ($service->kind->requiresParent() && $service->parentId !== null && isset($slugs[$service->parentId])) {
            $out['of'] = $slugs[$service->parentId];
        }

        if ($service->branchOverride !== null && $service->kind->hasConfigField('branch')) {
            $out['branch'] = $service->branchOverride;
        }

        if (! $service->enabled) {
            $out['enabled'] = false;
        }

        if ($service->kind->hasConfigField('build')) {
            $build = SettingsMap::changed(Arrays::assoc($service->build->toArray()), Arrays::assoc((new BuildSettings)->toArray()), ['disabledDecisions', 'generatedDockerfile']);

            if ($build !== []) {
                $out['build'] = $build;
            }
        }

        if ($service->kind->hasConfigField('deploy')) {
            $deploy = $this->deploy($service);

            if ($deploy !== []) {
                $out['deploy'] = $deploy;
            }
        }

        $scaling = SettingsMap::scalingToFile($service->scaling);

        if ($scaling !== []) {
            $out['scaling'] = $scaling;
        }

        $variables = $this->variables($project, $service, $generators);

        if ($variables !== []) {
            $out['variables'] = $variables;
        }

        if ($service->kind->isWeb()) {
            $domains = [];

            foreach ($service->domains as $domain) {
                if ($domain->kind === DomainKind::Custom) {
                    $domains[] = $domain->redirectTo === null ? $domain->hostname : ['hostname' => $domain->hostname, 'redirectTo' => $domain->redirectTo];
                }
            }

            if ($domains !== []) {
                $out['domains'] = $domains;
            }

            // Shared volumes (D73) belong to another service: not part of this one's file.
            $volumes = array_values(array_map(static fn (VolumeSpec $volume): string => $volume->mountPath, array_filter($service->volumes, static fn (VolumeSpec $volume): bool => $volume->sharedFrom === null)));
            sort($volumes);

            if ($volumes !== []) {
                $out['volumes'] = $volumes;
            }
        }

        $connections = [];

        foreach ($spec->connections as $connection) {
            if ($connection->fromServiceId !== $service->id || ! isset($slugs[$connection->toServiceId])) {
                continue;
            }

            $to = $slugs[$connection->toServiceId];
            $connections[$to] = $connection->mode === ConnectionMode::Private && $connection->style === ConnectionStyle::Url
                ? $to
                : array_filter(['to' => $to, 'mode' => $connection->mode === ConnectionMode::Private ? null : $connection->mode->value, 'style' => $connection->style === ConnectionStyle::Url ? null : $connection->style->value]);
        }

        if ($connections !== []) {
            ksort($connections);
            $out['connections'] = array_values($connections);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function deploy(ServiceSpec $service): array
    {
        $defaults = $service->kind->requiresParent() ? new DeploySettings(healthcheck: HealthcheckSettings::none()) : new DeploySettings;
        $current = Arrays::assoc($service->deploy->toArray());
        $default = Arrays::assoc($defaults->toArray());
        $health = $current['healthcheck'] ?? [];
        // Config files (D65) come from templates and Compose imports; the file keeps them as they are.
        unset($current['healthcheck'], $default['healthcheck'], $current['files'], $default['files']);

        $out = SettingsMap::changed($current, $default);
        $healthcheck = SettingsMap::changed(Arrays::assoc($health), Arrays::assoc($defaults->healthcheck->toArray()));

        if ($healthcheck !== []) {
            $out['healthcheck'] = $healthcheck;
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $generators
     * @return array<string, mixed>
     */
    private function variables(Project $project, ServiceSpec $service, array $generators): array
    {
        $source = $service->source();
        $driverKeys = $source instanceof DatabaseSource
            ? array_map(static fn (DriverVariable $variable): string => $variable->key, $this->engines->for($source->engine)->variables())
            : [];
        $out = [];

        foreach ($service->variables as $variable) {
            /** @var VariableSpec $variable */
            if ($variable->layer !== VariableLayer::Environment || in_array($variable->key, $driverKeys, true)) {
                continue;
            }

            $generator = $generators[$variable->id] ?? null;

            $out[$variable->key] = match (true) {
                $generator !== null => '${{ '.$generator.' }}',
                $variable->type === VariableType::Secret => ['secret' => true],
                default => $this->keyring->decryptVariable($project, $variable->id, $variable->version, $variable->ciphertext),
            };
        }

        ksort($out);

        return $out;
    }
}
