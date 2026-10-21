<?php

declare(strict_types=1);

namespace App\Support\ConfigAsCode;

use App\Data\ConfigAsCode\ConfigFileProblemData;
use App\Enums\VariableExposure;
use App\Enums\VariableType;
use App\Support\Cron\CronGrammar;
use App\Support\Hostname;
use App\Support\Images\ImageReference;
use App\Support\Variables\EnvFileParser;
use App\Support\Variables\VariableReferences;

/**
 * Checks a launchroom.json in three layers (docs/18 §6): JSON syntax with
 * positions (JsonDocument), the committed JSON Schema (ConfigSchema), and
 * the rules a schema can't express: what each kind may set, parents and
 * connections naming real services, kinds and engines that can't change,
 * valid variable expressions, and **no secret values**.
 */
final readonly class ConfigFileValidator
{
    /** Settings each kind may have besides kind, name, enabled, scaling, variables. */
    private const array ALLOWED = [
        'app' => ['rootDirectory', 'branch', 'build', 'deploy', 'domains', 'volumes', 'connections'],
        'image' => ['image', 'deploy', 'domains', 'volumes', 'connections'],
        'database' => ['engine', 'version'],
        'worker' => ['of', 'branch', 'deploy', 'connections'],
        'cron' => ['of', 'branch', 'deploy', 'connections'],
    ];

    private const array COMMON = ['kind', 'name', 'enabled', 'scaling', 'variables'];

    private const array FORBIDDEN_MOUNTS = ['/proc', '/sys', '/dev', '/var/run/docker.sock', '/run/docker.sock'];

    public function __construct(
        private ConfigSchema $schema,
    ) {}

    public function check(string $content, ConfigContext $context): ConfigCheck
    {
        try {
            $document = JsonDocument::parse($content);
        } catch (JsonSyntaxError $e) {
            return new ConfigCheck(null, [new Problems(null)->syntax($e, $content)]);
        }

        $schemaProblems = $this->schema->problems($document);

        if ($schemaProblems !== []) {
            return new ConfigCheck(null, $schemaProblems);
        }

        $file = ConfigFile::fromDocument($document);
        $problems = new Problems($document);
        $errors = [];
        $warnings = [];

        foreach ($file->forEnvironment('', false) as $service) {
            $this->service($service, $file, $context, $problems, $errors, $warnings, false);
        }

        foreach ($file->overrides as $environment => $services) {
            if ($environment !== ConfigFile::PREVIEWS && ! in_array($environment, $context->environments, true)) {
                $errors[] = $problems->at(JsonDocument::pointer('environments', $environment), "There is no environment \"{$environment}\" in this project.", $this->environmentHint($environment, $context), key: true);
            }

            foreach (array_keys($services) as $slug) {
                if (! isset($file->services[$slug])) {
                    $errors[] = $problems->at(JsonDocument::pointer('environments', $environment, 'services', $slug), "\"{$slug}\" isn't in services, so it has nothing to override.", 'Add it under "services" first, or fix the name.', key: true);
                }
            }

            $effective = $file->forEnvironment($environment, $environment === ConfigFile::PREVIEWS);

            foreach ($effective as $service) {
                if ($service->overridePointer !== null) {
                    $this->service($service, $file, $context, $problems, $errors, $warnings, true);
                }
            }
        }

        return new ConfigCheck($file, $errors, $warnings);
    }

    /**
     * @param  list<ConfigFileProblemData>  $errors
     * @param  list<ConfigFileProblemData>  $warnings
     */
    private function service(EffectiveService $service, ConfigFile $file, ConfigContext $context, Problems $problems, array &$errors, array &$warnings, bool $override): void
    {
        $kind = $service->kind();
        $slug = $service->slug;
        $checked = $override ? array_keys($service->override) : array_keys($service->config);
        $allowed = [...self::COMMON, ...self::ALLOWED[$kind]];
        $label = $kind === 'app' || $kind === 'image' ? "an {$kind} service" : "a {$kind}";

        foreach ($checked as $key) {
            if (! in_array($key, $allowed, true)) {
                $errors[] = $problems->at($service->pointerFor($key), "\"{$key}\" doesn't apply to {$label}.", $this->kindHint($kind, $key), key: true);
            }
        }

        if (! $override) {
            $this->identity($service, $file, $context, $problems, $errors);
        }

        if (in_array('scaling', $checked, true) && $kind === 'database') {
            $replicas = $service->group('scaling')['replicas'] ?? null;

            if (is_int($replicas) && $replicas > 1) {
                $errors[] = $problems->at($service->pointerFor('scaling', 'replicas'), 'Databases run a single replica.', 'Two instances can\'t share one data directory: use 1 (or 0 to stop it).');
            }
        }

        if (in_array('deploy', $checked, true)) {
            $this->schedule($service, $kind, $override, $problems, $errors);
        }

        if (in_array('variables', $checked, true)) {
            $this->variables($service, $problems, $errors, $warnings, $override);
        }

        if (in_array('domains', $checked, true)) {
            $this->domains($service, $problems, $errors);
        }

        if (in_array('volumes', $checked, true)) {
            foreach ($service->group('volumes') as $index => $path) {
                $path = is_string($path) ? $path : '';

                if (array_any(explode('/', $path), static fn (string $segment): bool => $segment === '.' || $segment === '..')
                    || array_any(self::FORBIDDEN_MOUNTS, static fn (string $forbidden): bool => $path === $forbidden || str_starts_with($path, $forbidden.'/'))) {
                    $errors[] = $problems->at($service->pointerFor('volumes', (string) $index), "\"{$path}\" can't be a volume.", 'Pick a clean path outside /proc, /sys and /dev, like /data.');
                }
            }
        }

        if (in_array('connections', $checked, true)) {
            foreach ($service->group('connections') as $index => $connection) {
                $to = is_array($connection) ? ($connection['to'] ?? null) : $connection;
                $pointer = $service->pointerFor('connections', (string) $index);

                if (! is_string($to)) {
                    continue;
                }

                $targetKind = $this->kindOf($to, $file, $context);

                if ($to === $slug) {
                    $errors[] = $problems->at($pointer, "{$slug} can't connect to itself.");
                } elseif ($targetKind === null) {
                    $errors[] = $problems->at($pointer, "There is no service \"{$to}\" to connect to.", $this->serviceHint($to, $file, $context));
                } elseif (in_array($targetKind, ['worker', 'cron'], true)) {
                    $errors[] = $problems->at($pointer, "{$to} is a {$targetKind}: it has no address to connect to.");
                } elseif ($targetKind === 'database' && is_array($connection) && ($connection['mode'] ?? 'private') === 'public') {
                    $errors[] = $problems->at($pointer, 'Databases are only reachable on the private network.', 'Remove "mode": "public".');
                }
            }
        }
    }

    /**
     * @param  list<ConfigFileProblemData>  $errors
     */
    private function identity(EffectiveService $service, ConfigFile $file, ConfigContext $context, Problems $problems, array &$errors): void
    {
        $kind = $service->kind();
        $slug = $service->slug;
        $existing = $context->services[$slug] ?? null;

        if ($existing !== null && $existing['kind']->value !== $kind) {
            $article = in_array($existing['kind']->value, ['app', 'image'], true) ? 'an' : 'a';
            $errors[] = $problems->at($service->pointerFor('kind'), "{$slug} is already {$article} {$existing['kind']->value} service; its kind can't change.", 'Use a new name for a new service, or write "kind": "'.$existing['kind']->value.'".');
        }

        // Existing services already have their source: only new ones need it.
        $required = $existing !== null ? [] : match ($kind) {
            'image' => ['image'],
            'database' => ['engine', 'version'],
            'worker', 'cron' => ['of'],
            default => [],
        };

        foreach ($required as $key) {
            if (! $service->has($key)) {
                $errors[] = $problems->at($service->pointer, "{$slug} needs \"{$key}\".", match ($key) {
                    'image' => 'Name the image to run, like "image": "ghcr.io/org/app:1.2".',
                    'engine' => 'Pick the engine: "engine": "postgres" (or mysql, redis, valkey, mongodb, clickhouse).',
                    'version' => 'Pick a major version, like "version": "18".',
                    default => 'Name the app whose image it runs, like "of": "api".',
                }, key: true);
            }
        }

        $image = $service->get('image');

        if (is_string($image) && ! ImageReference::tryParse($image) instanceof ImageReference) {
            $errors[] = $problems->at($service->pointerFor('image'), "\"{$image}\" isn't an image reference.", 'Like ghcr.io/org/app:1.2 or postgres@sha256:….');
        }

        if ($existing !== null && $kind === 'database' && $existing['engine'] !== null) {
            if ($service->get('engine') !== $existing['engine']) {
                $errors[] = $problems->at($service->pointerFor('engine'), "{$slug} runs {$existing['engine']}; the engine can't change.", 'Add a new database service for another engine.');
            } elseif ($service->get('version') !== $existing['version']) {
                $errors[] = $problems->at($service->pointerFor('version'), "{$slug} runs version {$existing['version']}.", 'Upgrades happen in the dashboard (they need a backup and a restore). Write "version": "'.$existing['version'].'".');
            }
        }

        $root = $service->get('rootDirectory');

        if (is_string($root) && array_any(explode('/', $root), static fn (string $segment): bool => $segment === '.' || $segment === '..')) {
            $errors[] = $problems->at($service->pointerFor('rootDirectory'), "\"{$root}\" leaves the repository.", 'Use a path inside the repository, like apps/web.');
        }

        $of = $service->get('of');

        if (is_string($of)) {
            $parent = $this->kindOf($of, $file, $context);

            if ($parent === null) {
                $errors[] = $problems->at($service->pointerFor('of'), "There is no service \"{$of}\".", $this->serviceHint($of, $file, $context));
            } elseif ($parent !== 'app') {
                $errors[] = $problems->at($service->pointerFor('of'), "{$of} is a {$parent}: workers and crons run the image of an app.");
            }
        }
    }

    /**
     * @param  list<ConfigFileProblemData>  $errors
     * @param  list<ConfigFileProblemData>  $warnings
     */
    private function variables(EffectiveService $service, Problems $problems, array &$errors, array &$warnings, bool $override): void
    {
        $variables = $override ? (is_array($service->override['variables'] ?? null) ? $service->override['variables'] : []) : $service->group('variables');

        foreach ($variables as $key => $value) {
            $key = (string) $key;
            $pointer = $service->pointerFor('variables', $key);

            if (is_array($value)) {
                if (VariableExposure::isBrowserInlined($key)) {
                    $errors[] = $problems->at($pointer, "{$key} is baked into the browser bundle, so it can't be a secret.", 'Anyone can read it in the page source: make it a config value, or rename it without the public prefix.', key: true);
                }

                continue;
            }

            if (! is_string($value)) {
                continue;
            }

            $invalid = VariableReferences::invalid($value);

            if ($invalid !== []) {
                $errors[] = $problems->at($pointer, "{$key} has an expression launchroom doesn't know: {$invalid[0]}.", 'References look like ${{postgres.DATABASE_URL}}, ${{shared.KEY}} or ${{KEY}}; generators like ${{ secret(32) }}.');

                continue;
            }

            $literal = (string) preg_replace('/\$\{\{.*?\}\}/s', '', $value);

            if (EnvFileParser::looksSecret($literal)) {
                $errors[] = $problems->at($pointer, "{$key} looks like a secret. Secret values never go in launchroom.json.", "Store it in the dashboard (or `launchroom env set --secret {$key}=…`) and write \"{$key}\": { \"secret\": true } here. If it was committed, rotate it.", mask: true);

                continue;
            }

            if ($literal !== '' && VariableType::suggestFor($key) === VariableType::Secret && ! VariableExposure::isBrowserInlined($key)) {
                $warnings[] = $problems->at($pointer, "{$key} usually holds a secret, but here it is a plain value in the repository.", "If it is secret, set it in the dashboard and write \"{$key}\": { \"secret\": true } here.", key: true);
            }
        }
    }

    /**
     * @param  list<ConfigFileProblemData>  $errors
     */
    private function domains(EffectiveService $service, Problems $problems, array &$errors): void
    {
        $seen = [];

        foreach ($service->group('domains') as $index => $domain) {
            $hostname = is_array($domain) ? ($domain['hostname'] ?? null) : $domain;
            $pointer = $service->pointerFor('domains', (string) $index);

            if (! is_string($hostname)) {
                continue;
            }

            $normalized = Hostname::normalize($hostname);

            if (! Hostname::isValid($normalized) || Hostname::isLocal($normalized)) {
                $errors[] = $problems->at($pointer, "\"{$hostname}\" can't be a domain.", 'Use a public domain name such as shop.example.com.');
            } elseif (isset($seen[$normalized])) {
                $errors[] = $problems->at($pointer, "{$hostname} is listed twice.");
            }

            $seen[$normalized] = true;
        }
    }

    private function kindOf(string $slug, ConfigFile $file, ConfigContext $context): ?string
    {
        $kind = $file->services[$slug]['kind'] ?? null;

        if (is_string($kind)) {
            return $kind;
        }

        $existing = $context->services[$slug] ?? null;

        return $existing === null ? null : $existing['kind']->value;
    }

    private function serviceHint(string $slug, ConfigFile $file, ConfigContext $context): string
    {
        $suggestion = ConfigSchema::suggest($slug, array_values(array_unique([...array_keys($file->services), ...array_keys($context->services)])));

        return $suggestion === null ? 'Add it under "services", or fix the name.' : "Did you mean \"{$suggestion}\"?";
    }

    private function environmentHint(string $environment, ConfigContext $context): string
    {
        $suggestion = ConfigSchema::suggest($environment, [...$context->environments, ConfigFile::PREVIEWS]);

        return $suggestion === null
            ? 'Use the slug of an environment ('.implode(', ', $context->environments).') or previews.'
            : "Did you mean \"{$suggestion}\"?";
    }

    /**
     * `deploy.schedule`: cron services only, launchroomd's cron grammar, an
     * IANA time zone (the schema checks the shape).
     *
     * @param  list<ConfigFileProblemData>  $errors
     */
    private function schedule(EffectiveService $service, ?string $kind, bool $override, Problems $problems, array &$errors): void
    {
        $deploy = $override ? Arrays::assoc($service->override['deploy'] ?? null) : $service->group('deploy');

        if (! array_key_exists('schedule', $deploy)) {
            return;
        }

        $schedule = $deploy['schedule'];

        if ($schedule !== null && $kind !== 'cron') {
            $errors[] = $problems->at($service->pointerFor('deploy', 'schedule'), '"schedule" only applies to cron services.', 'Make it a "kind": "cron" service whose app is "of".', key: true);

            return;
        }

        if (! is_array($schedule)) {
            return;
        }

        if (is_string($schedule['cron'] ?? null) && ($problem = CronGrammar::error($schedule['cron'])) !== null) {
            $errors[] = $problems->at($service->pointerFor('deploy', 'schedule', 'cron'), $problem, 'For example "*/5 * * * *" (every 5 minutes) or "0 3 * * *" (daily at 03:00).');
        }

        if (is_string($schedule['timeZone'] ?? null) && ($problem = CronGrammar::timeZoneError($schedule['timeZone'])) !== null) {
            $errors[] = $problems->at($service->pointerFor('deploy', 'schedule', 'timeZone'), $problem);
        }
    }

    private function kindHint(string $kind, string $key): ?string
    {
        return match (true) {
            $kind === 'database' && in_array($key, ['deploy', 'domains', 'volumes', 'build', 'connections'], true) => 'Databases are run by their engine driver: launchroom picks the command, port, health check and volume. Connect apps to the database instead.',
            in_array($kind, ['worker', 'cron'], true) && in_array($key, ['build', 'rootDirectory', 'image'], true) => 'Workers and crons run the image of their app ("of"): set the build there.',
            in_array($kind, ['worker', 'cron'], true) && $key === 'domains' => 'Workers and crons don\'t serve traffic.',
            $kind === 'image' && in_array($key, ['build', 'rootDirectory', 'branch'], true) => 'Image services run a prebuilt image; there is nothing to build.',
            default => null,
        };
    }
}
