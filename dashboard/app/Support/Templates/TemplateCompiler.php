<?php

declare(strict_types=1);

namespace App\Support\Templates;

use App\Data\Blueprints\BlueprintService;
use App\Data\Blueprints\BlueprintVariable;
use App\Data\Blueprints\ProjectBlueprint;
use App\Data\Templates\TemplateInputData;
use App\Enums\DatabaseEngine;
use App\Support\Blueprints\BlueprintValidator;
use App\Support\Canvas\ServiceGlyph;
use App\Support\Compose\ComposeRejected;
use App\Support\Compose\ComposeTranslation;
use App\Support\Compose\ComposeTranslator;
use App\Support\Compose\ComposeYaml;
use App\Support\Compose\TranslationOptions;
use App\Support\Compose\YamlLineIndex;
use App\Support\Hostname;
use App\Support\Slug;
use App\Support\Variables\GeneratedValues;

/**
 * Templates (`launchroom.template/v1`, docs/17 §3): parses a template file,
 * resolves its expressions and translates its services through the
 * Compose allow-list translator in template mode, so a template is valid
 * only when every line of it is something launchroom runs.
 *
 * Expressions: `${generate:password:32}` (also hex, base64, base64url with
 * a byte count, `${generate:uuid}` and `${generate:jwt:<role>:<KEY>}`, a
 * JWT signed with the variable KEY of the same service) become launchroom
 * generator expressions, evaluated per environment when the variable is
 * created and secret (except an anon JWT, public by design);
 * `${service.<key>.url|public_url|host|port|user|password|database|env.VAR}`
 * and `${self.public_url|domain|port|host|env.VAR}` become references;
 * `${input.<id>}` is the guided input's value. Top-level `configs` with
 * inline content become config files (literal: no expressions); `notes`
 * are shown on the detail sheet.
 */
final class TemplateCompiler
{
    public const string SCHEMA = 'launchroom.template/v1';

    public const array CATEGORIES = [
        'stacks' => 'Stacks',
        'analytics' => 'Analytics',
        'automation' => 'Automation',
        'content' => 'Content',
        'developer' => 'Developer tools',
        'storage' => 'Storage',
        'search' => 'Search',
        'monitoring' => 'Monitoring',
        'data' => 'Data',
    ];

    public const array INPUT_TYPES = ['text', 'email', 'domain', 'repository'];

    private const array TOP_LEVEL = ['schema', 'id', 'name', 'tagline', 'description', 'category', 'logo', 'website', 'license', 'notes', 'inputs', 'services', 'configs'];

    /** Short notes on the detail sheet: requirements and limits (D65). */
    public const int MAX_NOTES = 6;

    private const string EXPRESSION = '/\$\{(generate|service|self|input)([:.])([^}]*)\}/';

    private const string SENTINEL = "/\u{E000}(\\d+)\u{E001}/u";

    /**
     * @throws TemplateInvalid
     */
    public static function parse(string $yaml, string $id): TemplateDefinition
    {
        try {
            $data = ComposeYaml::parse($yaml);
        } catch (ComposeRejected $e) {
            throw new TemplateInvalid([$e->getMessage()]);
        }

        $problems = [];
        $string = static function (string $key, int $max, bool $optional = false) use ($data, &$problems): ?string {
            $value = $data[$key] ?? null;

            if ($value === null && $optional) {
                return null;
            }

            if (! is_string($value) || trim($value) === '' || mb_strlen($value) > $max) {
                $problems[] = "{$key} must be text of 1 to {$max} characters.";

                return null;
            }

            return trim($value);
        };

        if (($data['schema'] ?? null) !== self::SCHEMA) {
            $problems[] = 'schema must be '.self::SCHEMA.'.';
        }

        if (($data['id'] ?? null) !== $id || ! Slug::isValid($id)) {
            $problems[] = "id must be “{$id}” (the file name, a slug).";
        }

        foreach (array_keys($data) as $key) {
            if (! in_array($key, self::TOP_LEVEL, true)) {
                $problems[] = "Unknown key “{$key}”.";
            }
        }

        $name = $string('name', 60);
        $tagline = $string('tagline', 120);
        $description = $string('description', 600);
        $category = $string('category', 20);
        $logo = $string('logo', 64);
        $website = $string('website', 255, true);
        $license = $string('license', 40, true);

        if ($category !== null && ! isset(self::CATEGORIES[$category])) {
            $problems[] = "Unknown category “{$category}”.";
        }

        if ($website !== null && preg_match('#^https://[a-z0-9.-]+(?:/\S*)?$#iD', $website) !== 1) {
            $problems[] = 'website must be an https URL.';
        }

        $inputs = [];

        foreach (is_array($data['inputs'] ?? null) ? $data['inputs'] : [] as $index => $input) {
            if (! is_array($input)) {
                $problems[] = "inputs.{$index} must be a mapping.";

                continue;
            }

            $inputId = is_string($input['id'] ?? null) ? $input['id'] : '';
            $type = is_string($input['type'] ?? null) ? $input['type'] : '';
            $label = is_string($input['label'] ?? null) ? trim($input['label']) : '';

            if (preg_match('/^[a-z][A-Za-z0-9_]{0,31}$/D', $inputId) !== 1 || ! in_array($type, self::INPUT_TYPES, true) || $label === '' || mb_strlen($label) > 60) {
                $problems[] = "inputs.{$index} needs an id, a label and a type (".implode(', ', self::INPUT_TYPES).').';

                continue;
            }

            if (array_any($inputs, static fn (TemplateInputData $other): bool => $other->id === $inputId)) {
                $problems[] = "The input {$inputId} is defined twice.";
            }

            $inputs[] = new TemplateInputData(
                id: $inputId,
                label: $label,
                type: $type,
                optional: ($input['optional'] ?? false) === true,
                description: is_string($input['description'] ?? null) ? mb_substr($input['description'], 0, 200) : null,
                placeholder: is_string($input['placeholder'] ?? null) ? mb_substr($input['placeholder'], 0, 100) : null,
            );
        }

        $notes = [];

        foreach (is_array($data['notes'] ?? null) ? $data['notes'] : [] as $index => $note) {
            if (! is_string($note) || trim($note) === '' || mb_strlen($note) > 240) {
                $problems[] = "notes.{$index} must be text of 1 to 240 characters.";

                continue;
            }

            $notes[] = trim((string) preg_replace('/\s+/', ' ', $note));
        }

        if (count($notes) > self::MAX_NOTES) {
            $problems[] = 'A template has at most '.self::MAX_NOTES.' notes.';
        }

        $configs = $data['configs'] ?? [];

        if (! is_array($configs) || ($configs !== [] && array_is_list($configs))) {
            $problems[] = 'configs must be a mapping of Compose configs.';
            $configs = [];
        }

        if (count($inputs) > 6) {
            $problems[] = 'A template asks for at most 6 inputs.';
        }

        $services = $data['services'] ?? null;

        if (! is_array($services) || $services === [] || array_is_list($services)) {
            $problems[] = 'services must be a mapping of Compose services.';
            $services = [];
        }

        if ($problems !== [] || $name === null || $tagline === null || $description === null || $category === null || $logo === null) {
            throw new TemplateInvalid($problems === [] ? ['The template is incomplete.'] : $problems);
        }

        return new TemplateDefinition($id, $name, $tagline, $description, $category, $logo, $website, $license, $inputs, $services, YamlLineIndex::of($yaml), $notes, $configs);
    }

    /**
     * The template's blueprint for these inputs.
     *
     * @param  array<string, string>  $inputs  input id => value (the repository input is handled by the caller)
     * @param  array<string, array{versions: list<string>, default: string}>  $engines
     *
     * @throws TemplateInvalid
     */
    public static function compile(TemplateDefinition $template, array $inputs, array $engines, bool $repository = false, ?string $name = null): ComposeTranslation
    {
        $values = self::inputs($template, $inputs);
        $placeholders = [];
        $problems = [];
        $services = self::resolve($template->services, $template, $values, $placeholders, $problems);

        if ($problems !== []) {
            throw new TemplateInvalid(array_values(array_unique($problems)));
        }

        try {
            $translation = ComposeTranslator::translateData(['services' => $services, ...($template->configs === [] ? [] : ['configs' => $template->configs])], $template->lines, new TranslationOptions(
                repository: $repository,
                template: true,
                engines: $engines,
                name: $name ?? $template->name,
            ));
        } catch (ComposeRejected $e) {
            throw new TemplateInvalid([$e->getMessage()]);
        }

        $blueprint = $translation->blueprint;

        $services = array_map(static function (BlueprintService $service) use ($placeholders, &$problems): BlueprintService {
            $copy = clone $service;
            $copy->variables = self::restore($service->variables, $placeholders);
            $copy->provides = array_map(
                static fn (BlueprintVariable $variable): BlueprintVariable => new BlueprintVariable($variable->key, (string) preg_replace_callback(self::SENTINEL, static fn (array $m): string => $placeholders[(int) $m[1]] ?? '', $variable->value)),
                $service->provides,
            );
            $rest = json_encode([$service->entrypoint, $service->startCommand, $service->domain, $service->image, $service->volumes, $service->healthcheck?->path]);

            if (is_string($rest) && preg_match('/\\\\ue000/', $rest) === 1) {
                $problems[] = "{$service->key}: expressions like \${generate:…} and \${service.…} are only allowed in environment values.";
            }

            return $copy;
        }, $blueprint->services);

        $shared = self::restore($blueprint->sharedVariables, $placeholders);
        $blueprint = new ProjectBlueprint($blueprint->name, $services, $shared);
        $problems = [...$problems, ...BlueprintValidator::errors($blueprint)];

        if ($problems !== []) {
            throw new TemplateInvalid(array_values(array_unique($problems)));
        }

        return new ComposeTranslation($blueprint, $translation->notes, [], [], $translation->skipped, $translation->choices);
    }

    /**
     * Sentinels back to launchroom expressions; generated values are secrets,
     * except a JWT for the `anon` role: it is public by design (Supabase's
     * anon key ships in browser bundles; row level security protects the
     * data), and as config it can feed `NEXT_PUBLIC_*` variables.
     *
     * @param  list<BlueprintVariable>  $variables
     * @param  list<string>  $placeholders
     * @return list<BlueprintVariable>
     */
    private static function restore(array $variables, array $placeholders): array
    {
        $restored = [];

        foreach ($variables as $variable) {
            $value = (string) preg_replace_callback(self::SENTINEL, static fn (array $m): string => $placeholders[(int) $m[1]] ?? '', $variable->value);
            $publicJwt = GeneratedValues::jwtRole(GeneratedValues::sole($value)) === 'anon';
            $restored[] = new BlueprintVariable($variable->key, $value, ! $publicJwt && ($variable->secret || str_contains($value, '${{ secret(') || str_contains($value, '${{ uuid() }}') || str_contains($value, '${{ jwt(')));
        }

        return $restored;
    }

    /**
     * Everything that keeps a template out of the catalog (docs/17 §3.3).
     *
     * @param  array<string, array{versions: list<string>, default: string}>  $engines
     * @return list<string>
     */
    public static function lint(TemplateDefinition $template, array $engines): array
    {
        $problems = [];

        if (! in_array($template->logo, ServiceGlyph::KNOWN, true)) {
            $problems[] = "The logo “{$template->logo}” isn’t a known glyph.";
        }

        $samples = [];

        foreach ($template->inputs as $input) {
            $samples[$input->id] = match ($input->type) {
                'email' => 'admin@example.com',
                'domain' => 'app.example.com',
                default => 'example',
            };

            if ($input->type !== 'repository' && ! str_contains((string) json_encode($template->services), '${input.'.$input->id.'}')) {
                $problems[] = "The input {$input->id} is never used.";
            }
        }

        foreach ($template->services as $name => $service) {
            $name = (string) $name;

            if (! Slug::isValid($name) || in_array($name, Slug::RESERVED, true)) {
                $problems[] = "The service name {$name} must be a slug.";
            }

            if (! is_array($service)) {
                continue;
            }

            if (isset($service['image'])) {
                $image = is_string($service['image']) ? $service['image'] : '';

                if (preg_match('#^(docker\.io|ghcr\.io)/[a-z0-9._/-]+:[A-Za-z0-9_.-]+@sha256:[a-f0-9]{64}$#D', $image) !== 1) {
                    $problems[] = "{$name}: the image must be docker.io/… or ghcr.io/… with a tag and pinned by digest (@sha256:…).";
                }
            }

            $logo = is_array($service['x-launchroom'] ?? null) ? ($service['x-launchroom']['logo'] ?? null) : null;

            if ($logo !== null && (! is_string($logo) || ! in_array($logo, ServiceGlyph::KNOWN, true))) {
                $problems[] = "{$name}: the logo isn’t a known glyph.";
            }
        }

        foreach ($template->needsRepository() ? [true] : [false] as $repository) {
            try {
                $translation = self::compile($template, $samples, $engines, $repository);
                $problems = [...$problems, ...$translation->strictProblems()];
            } catch (TemplateInvalid $e) {
                $problems = [...$problems, ...$e->problems];
            }
        }

        return array_values(array_unique($problems));
    }

    /**
     * @param  array<string, string>  $inputs
     * @return array<string, string>
     *
     * @throws TemplateInvalid
     */
    private static function inputs(TemplateDefinition $template, array $inputs): array
    {
        $values = [];
        $problems = [];

        foreach ($template->inputs as $input) {
            if ($input->type === 'repository') {
                continue;
            }

            $value = trim($inputs[$input->id] ?? '');

            if ($value === '') {
                if (! $input->optional) {
                    $problems[] = "{$input->label} is required.";
                }

                $values[$input->id] = '';

                continue;
            }

            $valid = match ($input->type) {
                'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false && strlen($value) <= 254,
                'domain' => Hostname::isValid(strtolower($value)),
                default => mb_strlen($value) <= 200 && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1,
            };

            if (! $valid) {
                $problems[] = match ($input->type) {
                    'email' => "{$input->label}: enter an email address.",
                    'domain' => "{$input->label}: enter a domain like app.example.com.",
                    default => "{$input->label}: use up to 200 characters on one line.",
                };
            }

            $values[$input->id] = $input->type === 'domain' ? strtolower($value) : $value;
        }

        if ($problems !== []) {
            throw new TemplateInvalid($problems, 'inputs');
        }

        return $values;
    }

    /**
     * Replaces expressions in every string of the services: inputs by their
     * value, the rest by sentinels restored after the translation.
     *
     * @param  array<array-key, mixed>  $node
     * @param  array<string, string>  $values
     * @param  list<string>  $placeholders
     * @param  list<string>  $problems
     * @return array<array-key, mixed>
     */
    private static function resolve(array $node, TemplateDefinition $template, array $values, array &$placeholders, array &$problems): array
    {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = self::resolve($value, $template, $values, $placeholders, $problems);
            } elseif (is_string($value)) {
                $node[$key] = (string) preg_replace_callback(self::EXPRESSION, static function (array $m) use ($template, $values, &$placeholders, &$problems): string {
                    if ($m[1] === 'input') {
                        if ($m[2] !== '.' || ! array_key_exists($m[3], $values)) {
                            $problems[] = "Unknown input in {$m[0]}.";

                            return '';
                        }

                        return str_replace('$', '$$', $values[$m[3]]);
                    }

                    $expression = self::expression($m[1], $m[2], $m[3], $template);

                    if ($expression === null) {
                        $problems[] = "{$m[0]} isn’t a template expression launchroom knows.";

                        return '';
                    }

                    $placeholders[] = $expression;

                    return "\u{E000}".(count($placeholders) - 1)."\u{E001}";
                }, $value);
            }
        }

        return $node;
    }

    private static function expression(string $kind, string $separator, string $rest, TemplateDefinition $template): ?string
    {
        if ($kind === 'generate') {
            if ($separator !== ':') {
                return null;
            }

            if ($rest === 'uuid') {
                return '${{ uuid() }}';
            }

            // `${generate:jwt:anon:JWT_SECRET}`: signed with that variable of the same service.
            if (preg_match('/^jwt:([a-z_][a-z0-9_]{0,62}):([A-Za-z_][A-Za-z0-9_]{0,255})$/D', $rest, $m) === 1) {
                return "\${{ jwt(\"{$m[1]}\", {$m[2]}) }}";
            }

            if (preg_match('/^(password|hex|base64|base64url):(\d{1,4})$/D', $rest, $m) !== 1 || (int) $m[2] < 8 || (int) $m[2] > 1024) {
                return null;
            }

            return $m[1] === 'password' ? "\${{ secret({$m[2]}) }}" : "\${{ secret({$m[2]}, \"{$m[1]}\") }}";
        }

        if ($separator !== '.') {
            return null;
        }

        if ($kind === 'self') {
            if (preg_match('/^env\.([A-Za-z_][A-Za-z0-9_]{0,255})$/D', $rest, $m) === 1) {
                return '${{'.$m[1].'}}';
            }

            return match ($rest) {
                'public_url' => '${{PUBLIC_URL}}',
                'domain' => '${{DOMAIN}}',
                'port' => '${{PORT}}',
                'host' => '${{PRIVATE_HOST}}',
                default => null,
            };
        }

        if (preg_match('/^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)\.(url|public_url|host|port|user|password|database|env\.[A-Za-z_][A-Za-z0-9_]*)$/D', $rest, $m) !== 1
            || ! is_array($template->services[$m[1]] ?? null)) {
            return null;
        }

        $service = $template->services[$m[1]];
        $engineName = is_array($service['x-launchroom'] ?? null) && is_array($service['x-launchroom']['database'] ?? null) && is_string($service['x-launchroom']['database']['engine'] ?? null)
            ? $service['x-launchroom']['database']['engine']
            : null;
        $engine = $engineName === null ? null : DatabaseEngine::tryFrom($engineName);
        $redis = in_array($engine, [DatabaseEngine::Redis, DatabaseEngine::Valkey], true);
        $key = $m[1];

        if (str_starts_with($m[2], 'env.')) {
            return "\${{{$key}.".substr($m[2], 4).'}}';
        }

        $export = match (true) {
            $engine === null => match ($m[2]) {
                'url' => 'PRIVATE_URL',
                'public_url' => 'PUBLIC_URL',
                'host' => 'PRIVATE_HOST',
                'port' => 'PORT',
                default => null,
            },
            $redis => match ($m[2]) {
                'url' => 'REDIS_URL',
                'host' => 'REDIS_HOST',
                'port' => 'REDIS_PORT',
                'password' => 'REDIS_PASSWORD',
                default => null,
            },
            $engine === DatabaseEngine::MongoDb => match ($m[2]) {
                'url' => 'MONGO_URL',
                'host' => 'MONGOHOST',
                'port' => 'MONGOPORT',
                'user' => 'MONGOUSER',
                'password' => 'MONGOPASSWORD',
                'database' => 'MONGODATABASE',
                default => null,
            },
            $engine === DatabaseEngine::ClickHouse => match ($m[2]) {
                'url' => 'CLICKHOUSE_URL',
                'host' => 'CLICKHOUSE_HOST',
                'port' => 'CLICKHOUSE_PORT',
                'user' => 'CLICKHOUSE_USER',
                'password' => 'CLICKHOUSE_PASSWORD',
                'database' => 'CLICKHOUSE_DATABASE',
                default => null,
            },
            $engine === DatabaseEngine::Postgres => match ($m[2]) {
                'url' => 'DATABASE_URL',
                'host' => 'PGHOST',
                'port' => 'PGPORT',
                'user' => 'PGUSER',
                'password' => 'PGPASSWORD',
                'database' => 'PGDATABASE',
                default => null,
            },
            default => match ($m[2]) {
                'url' => 'DATABASE_URL',
                'host' => 'MYSQLHOST',
                'port' => 'MYSQLPORT',
                'user' => 'MYSQL_USER',
                'password' => 'MYSQL_PASSWORD',
                'database' => 'MYSQL_DATABASE',
                default => null,
            },
        };

        return $export === null ? null : "\${{{$key}.{$export}}}";
    }
}
