<?php

declare(strict_types=1);

namespace App\Support\ConfigAsCode;

use App\Data\ConfigAsCode\ConfigFileProblemData;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;
use RuntimeException;
use stdClass;

/**
 * The committed launchroom.json JSON Schema (docs/18 §2.2) and its errors in
 * plain words: unknown keys with "did you mean", wrong types with an
 * example, enums listing their values.
 */
final class ConfigSchema
{
    public const string ID = 'https://launchroom.vantezzen.io/schema/launchroom.v1.json';

    private const int MAX_PROBLEMS = 20;

    private static ?Validator $validator = null;

    public static function path(): string
    {
        return resource_path('schema/launchroom.v1.json');
    }

    /**
     * @return list<ConfigFileProblemData>
     */
    public function problems(JsonDocument $document): array
    {
        $error = $this->validator()->validate($document->value, self::ID)->error();

        if (! $error instanceof ValidationError) {
            return [];
        }

        $problems = new Problems($document);
        $out = [];

        foreach ($this->leaves($error) as $leaf) {
            $problem = $this->describe($leaf, $problems);
            $out[($problem->path ?? '').'|'.$problem->message] = $problem;
        }

        return array_slice(array_values($out), 0, self::MAX_PROBLEMS);
    }

    private function validator(): Validator
    {
        if (! self::$validator instanceof Validator) {
            $path = self::path();

            if (! is_file($path)) {
                throw new RuntimeException("launchroom.json schema not found at {$path}.");
            }

            $validator = new Validator;
            $validator->resolver()?->registerFile(self::ID, $path);
            $validator->setMaxErrors(self::MAX_PROBLEMS);
            self::$validator = $validator;
        }

        return self::$validator;
    }

    /**
     * The errors worth reporting: the innermost ones, and for `anyOf` only
     * the branches whose type matched the data.
     *
     * @return list<ValidationError>
     */
    private function leaves(ValidationError $error): array
    {
        /** @var list<ValidationError> $subs */
        $subs = array_values($error->subErrors());

        if ($error->keyword() === 'anyOf') {
            $matching = array_values(array_filter($subs, static fn (ValidationError $sub): bool => $sub->keyword() !== 'type'));

            if ($matching === []) {
                return [$error];
            }

            return $this->leaves($matching[0]);
        }

        if ($error->keyword() === 'propertyNames' || $subs === []) {
            return [$error];
        }

        $out = [];

        foreach ($subs as $sub) {
            array_push($out, ...$this->leaves($sub));
        }

        return $out;
    }

    private function describe(ValidationError $error, Problems $problems): ConfigFileProblemData
    {
        $segments = Arrays::strings($error->data()->fullPath());
        $pointer = JsonDocument::pointer(...$segments);
        $field = $segments === [] ? 'The file' : '"'.end($segments).'"';
        $schema = $error->schema()->info()->data();
        $schema = $schema instanceof stdClass ? $schema : new stdClass;
        $args = $error->args();
        $value = $error->data()->value();

        switch ($error->keyword()) {
            case 'additionalProperties':
                $extra = Arrays::strings($args['properties'] ?? []);
                $name = $extra[0] ?? '?';
                $properties = $schema->properties ?? null;
                $allowed = $properties instanceof stdClass ? array_map(strval(...), array_keys(get_object_vars($properties))) : [];
                $suggestion = self::suggest($name, $allowed);
                $where = $segments === [] ? 'at the top of the file' : 'in '.Problems::path($pointer);

                return $problems->at(
                    $pointer.JsonDocument::pointer($name),
                    "\"{$name}\" isn't a setting {$where}.",
                    $suggestion !== null ? "Did you mean \"{$suggestion}\"?" : ($allowed === [] ? null : 'Allowed here: '.implode(', ', $allowed).'.'),
                    key: true,
                );

            case 'required':
                $missing = Arrays::strings($args['missing'] ?? []);
                $name = $missing[0] ?? '?';

                return $problems->at($pointer, ($segments === [] ? 'The file' : Problems::path($pointer)).' needs "'.$name.'".', $this->requiredHint($name));

            case 'type':
                $expected = Arrays::strings((array) ($args['expected'] ?? []));

                return $problems->at($pointer, "{$field} should be ".$this->types($expected).'.', $this->typeHint($segments, $value, $expected));

            case 'anyOf':
                $types = [];

                foreach ($error->subErrors() as $sub) {
                    if ($sub instanceof ValidationError) {
                        array_push($types, ...Arrays::strings((array) ($sub->args()['expected'] ?? [])));
                    }
                }

                $types = array_values(array_unique($types));

                return $problems->at($pointer, "{$field} should be ".$this->types($types).'.', $this->typeHint($segments, $value, $types));

            case 'enum':
                $options = Arrays::strings((array) ($schema->enum ?? []));
                $suggestion = is_string($value) ? self::suggest($value, $options) : null;

                return $problems->at($pointer, "{$field} must be one of: ".implode(', ', $options).'.', $suggestion !== null ? "Did you mean \"{$suggestion}\"?" : null);

            case 'const':
                return end($segments) === 'version' && $segments === ['version']
                    ? $problems->at($pointer, 'This dashboard reads version 1 of launchroom.json.', 'Write "version": 1.')
                    : $problems->at($pointer, "{$field} must be ".json_encode($args['const'] ?? null, JSON_THROW_ON_ERROR).'.');

            case 'minimum':
                return $problems->at($pointer, "{$field} should be at least ".$this->number($args['min'] ?? null).'.');

            case 'maximum':
                return $problems->at($pointer, "{$field} should be at most ".$this->number($args['max'] ?? null).'.');

            case 'minLength':
                return $problems->at($pointer, "{$field} can't be empty.");

            case 'maxLength':
                return $problems->at($pointer, "{$field} is too long (at most ".$this->number($args['max'] ?? null).' characters).');

            case 'minItems':
                return $problems->at($pointer, "{$field} needs at least one item.");

            case 'maxItems':
                return $problems->at($pointer, "{$field} has too many items (at most ".$this->number($args['max'] ?? $schema->maxItems ?? null).').');

            case 'maxProperties':
                return $problems->at($pointer, "{$field} has too many entries (at most ".$this->number($args['max'] ?? $schema->maxProperties ?? null).').');

            case 'propertyNames':
                $name = Arrays::str($args['property'] ?? '?');

                return $problems->at($pointer.JsonDocument::pointer($name), $this->nameMessage($segments, $name), $this->nameHint($segments), key: true);

            case 'pattern':
                return $problems->at($pointer, $this->patternMessage($segments, $field), $this->patternHint($segments));

            case 'not':
                return $problems->at($pointer, "{$field} is reserved.");

            default:
                return $problems->at($pointer, "{$field} is not valid here.");
        }
    }

    /**
     * @param  list<string>  $options
     */
    public static function suggest(string $input, array $options): ?string
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($options as $option) {
            if (strcasecmp($input, $option) === 0) {
                return $option;
            }

            $distance = levenshtein(strtolower($input), strtolower($option));

            if ($distance < $bestDistance) {
                [$best, $bestDistance] = [$option, $distance];
            }
        }

        return $best !== null && $bestDistance <= max(2, intdiv(strlen($input), 3)) ? $best : null;
    }

    /**
     * @param  array<array-key, mixed>  $types
     */
    private function types(array $types): string
    {
        $words = array_values(array_unique(array_map(static fn (mixed $type): string => match ($type) {
            'string' => 'text in double quotes',
            'integer' => 'a whole number',
            'number' => 'a number',
            'boolean' => 'true or false',
            'array' => 'a list [ … ]',
            'object' => 'an object { … }',
            'null' => 'null',
            default => (string) (is_scalar($type) ? $type : 'a value'),
        }, $types)));
        $last = array_pop($words);

        return $words === [] ? (string) $last : implode(', ', $words).' or '.$last;
    }

    /**
     * @param  list<string>  $segments
     * @param  array<array-key, mixed>  $expected
     */
    private function typeHint(array $segments, mixed $value, array $expected): ?string
    {
        $last = (string) end($segments);

        if (is_string($value) && in_array('array', $expected, true) && str_ends_with($last, 'Command')) {
            $argv = array_values(array_filter(preg_split('/\s+/', trim($value)) ?: [], static fn (string $part): bool => $part !== ''));

            $list = implode(', ', array_map(static fn (string $part): string => json_encode($part, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $argv));

            return "Commands are lists of arguments (never run through a shell): [{$list}].";
        }

        if (is_string($value) && in_array('integer', $expected, true) && is_numeric($value)) {
            return 'Remove the quotes: '.$value.'.';
        }

        if (is_string($value) && in_array('boolean', $expected, true) && in_array(strtolower($value), ['true', 'false'], true)) {
            return 'Remove the quotes: '.strtolower($value).'.';
        }

        if (($segments[0] ?? null) === 'services' && count($segments) === 4 && $segments[2] === 'variables' && ! is_string($value)) {
            return 'Write the value as text ("'.(is_scalar($value) ? json_encode($value, JSON_THROW_ON_ERROR) : '…').'"), or { "secret": true } for a secret set in the dashboard.';
        }

        return null;
    }

    private function requiredHint(string $name): ?string
    {
        return match ($name) {
            'version' => 'Add "version": 1.',
            'services' => 'Add "services": { } with your services.',
            'kind' => 'Add "kind": "app" (or image, database, worker, cron).',
            'secret' => 'Write { "secret": true } for a secret whose value is set in the dashboard.',
            'hostname' => 'Write { "hostname": "shop.example.com" } or the plain string "shop.example.com".',
            'to' => 'Write { "to": "postgres" } or the plain string "postgres".',
            default => null,
        };
    }

    /**
     * @param  list<string>  $segments
     */
    private function nameMessage(array $segments, string $name): string
    {
        $parent = (string) end($segments);

        return match ($parent) {
            'services' => "\"{$name}\" can't be a service name.",
            'environments' => "\"{$name}\" can't be an environment name.",
            'variables' => "\"{$name}\" can't be a variable name.",
            'labels' => "\"{$name}\" can't be a server label.",
            default => "\"{$name}\" isn't a valid name here.",
        };
    }

    /**
     * @param  list<string>  $segments
     */
    private function nameHint(array $segments): ?string
    {
        return match ((string) end($segments)) {
            'services' => 'Service names are lowercase letters, digits and dashes (they become hostnames like postgres or api-gateway); shared, launchroom and localhost are reserved.',
            'environments' => 'Use the environment\'s slug (production, staging) or previews.',
            'variables' => 'Variable names are letters, digits and _, not starting with a digit.',
            'labels' => 'Label names are lowercase letters, digits, dots, dashes and underscores.',
            default => null,
        };
    }

    /**
     * @param  list<string>  $segments
     */
    private function patternMessage(array $segments, string $field): string
    {
        $last = (string) end($segments);
        $parent = $segments[count($segments) - 2] ?? '';

        return match (true) {
            $last === 'of' || $parent === 'connections' || $last === 'to' => "{$field} isn't a service name.",
            $parent === 'domains' || $last === 'hostname' || $last === 'redirectTo' => "{$field} isn't a domain name.",
            $parent === 'volumes' => "{$field} isn't a valid mount path.",
            $last === 'rootDirectory' => "{$field} isn't a valid directory.",
            $last === 'version' => "{$field} isn't a version number.",
            $last === 'path' => "{$field} isn't a URL path.",
            $last === 'expectStatus' => "{$field} isn't a status range.",
            $last === 'branch' => "{$field} isn't a branch name.",
            default => "{$field} has an invalid format.",
        };
    }

    /**
     * @param  list<string>  $segments
     */
    private function patternHint(array $segments): ?string
    {
        $last = (string) end($segments);
        $parent = $segments[count($segments) - 2] ?? '';

        return match (true) {
            $parent === 'domains' || $last === 'hostname' || $last === 'redirectTo' => 'Write the domain alone, like shop.example.com (no https://, no path).',
            $parent === 'volumes' => 'An absolute path inside the container, like /data.',
            $last === 'rootDirectory' => 'A path inside the repository, like apps/web, without a leading /.',
            $last === 'version' => 'A major version in quotes, like "18".',
            $last === 'path' => 'Start with /, like /health.',
            $last === 'expectStatus' => 'Like 2xx, 200 or 2xx-3xx.',
            default => null,
        };
    }

    private function number(mixed $value): string
    {
        return is_int($value) || is_float($value) ? (string) $value : (is_string($value) ? $value : '?');
    }
}
