<?php

declare(strict_types=1);

namespace App\Http\Api\OpenApi;

use BackedEnum;
use Illuminate\Support\Facades\File;
use LogicException;
use ReflectionClass;
use ReflectionEnum;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionUnionType;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * JSON Schema (2020-12, as OpenAPI 3.1 uses it) for Data classes and
 * backed enums, by reflection over constructor-promoted properties
 * (docs/16 §2.4): PHP types, `?` nullability, `list<X>` and
 * `array<string, X>` docblocks, laravel-data validation attributes,
 * defaults, and property doc comments as descriptions. Every class and
 * enum becomes one component; references point at it.
 */
final class SchemaBuilder
{
    /** @var array<string, array<string, mixed>> component name => schema */
    private array $components = [];

    /** @var array<string, class-string> component name => class (collision check) */
    private array $owners = [];

    /**
     * @return array<string, array<string, mixed>>
     */
    public function components(): array
    {
        ksort($this->components);

        return $this->components;
    }

    /**
     * @param  class-string<Data>|class-string<BackedEnum>  $class
     * @return array{'$ref': string}
     */
    public function reference(string $class): array
    {
        $name = $this->register($class);

        return ['$ref' => '#/components/schemas/'.$name];
    }

    /**
     * Query parameters of a Data class (one per property).
     *
     * @param  class-string<Data>  $class
     * @return list<array<string, mixed>>
     */
    public function queryParameters(string $class): array
    {
        $parameters = [];

        foreach ($this->properties($class) as [$parameter, $property]) {
            [$schema, $required] = $this->property($class, $parameter, $property);
            $description = $schema['description'] ?? null;
            unset($schema['description']);

            $parameters[] = array_filter([
                'name' => $parameter->getName(),
                'in' => 'query',
                'required' => $required && ! $parameter->isDefaultValueAvailable(),
                'description' => $description,
                'schema' => $schema,
            ], static fn (mixed $value): bool => $value !== null && $value !== false);
        }

        return $parameters;
    }

    /**
     * @param  class-string  $class
     */
    private function register(string $class): string
    {
        $name = new ReflectionClass($class)->getShortName();

        if (isset($this->owners[$name])) {
            if ($this->owners[$name] !== $class) {
                throw new LogicException("Two API schemas are called {$name}: {$this->owners[$name]} and {$class}.");
            }

            return $name;
        }

        $this->owners[$name] = $class;
        $this->components[$name] = [];
        $this->components[$name] = is_subclass_of($class, BackedEnum::class) ? $this->enum($class) : $this->object($class);

        return $name;
    }

    /**
     * @param  class-string<BackedEnum>  $class
     * @return array<string, mixed>
     */
    private function enum(string $class): array
    {
        $reflection = new ReflectionEnum($class);
        $backing = (string) $reflection->getBackingType();

        return array_filter([
            'type' => $backing === 'int' ? 'integer' : 'string',
            'enum' => array_map(static fn (BackedEnum $case): int|string => $case->value, $class::cases()),
            'description' => $this->summary((string) $reflection->getDocComment()),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  class-string  $class
     * @return array<string, mixed>
     */
    private function object(string $class): array
    {
        $properties = [];
        $required = [];

        foreach ($this->properties($class) as [$parameter, $property]) {
            [$schema, $isRequired] = $this->property($class, $parameter, $property);
            $properties[$parameter->getName()] = $schema;

            if ($isRequired) {
                $required[] = $parameter->getName();
            }
        }

        return array_filter([
            'type' => 'object',
            'description' => $this->summary((string) new ReflectionClass($class)->getDocComment()),
            'properties' => $properties === [] ? null : $properties,
            'required' => $required === [] ? null : $required,
            'additionalProperties' => false,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  class-string  $class
     * @return list<array{ReflectionParameter, ReflectionProperty}>
     */
    private function properties(string $class): array
    {
        $constructor = new ReflectionClass($class)->getConstructor();
        $result = [];

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            if ($parameter->isPromoted()) {
                $result[] = [$parameter, new ReflectionProperty($class, $parameter->getName())];
            }
        }

        return $result;
    }

    /**
     * @param  class-string  $class
     * @return array{array<string, mixed>, bool} schema and whether it is required
     */
    private function property(string $class, ReflectionParameter $parameter, ReflectionProperty $property): array
    {
        $type = $parameter->getType();
        $names = match (true) {
            $type instanceof ReflectionNamedType => [$type->getName()],
            $type instanceof ReflectionUnionType => array_map(static fn (\ReflectionNamedType|\ReflectionIntersectionType $part): string => $part instanceof ReflectionNamedType ? $part->getName() : 'mixed', $type->getTypes()),
            default => ['mixed'],
        };

        $optional = in_array(Optional::class, $names, true);
        $nullable = ($type?->allowsNull() ?? true) || in_array('null', $names, true);
        $names = array_values(array_filter($names, static fn (string $name): bool => $name !== Optional::class && $name !== 'null'));

        if (count($names) !== 1) {
            throw new LogicException("{$class}::\${$parameter->getName()} needs exactly one API type.");
        }

        $schema = $this->type($class, $parameter->getName(), $names[0]);
        $schema = [...$schema, ...$this->constraints($property, $schema)];

        if ($parameter->isDefaultValueAvailable()) {
            $default = $parameter->getDefaultValue();

            if ($default instanceof BackedEnum) {
                $schema['default'] = $default->value;
            } elseif (is_scalar($default)) {
                $schema['default'] = $default;
            }
        }

        if ($nullable) {
            $schema = isset($schema['$ref']) || isset($schema['enum'])
                ? ['oneOf' => [$schema, ['type' => 'null']]]
                : [...$schema, 'type' => [$schema['type'], 'null']];
        }

        $description = $this->summary((string) $property->getDocComment());

        if ($description !== null) {
            $schema = ['description' => $description, ...$schema];
        }

        return [$schema, ! $optional && ! $parameter->isDefaultValueAvailable()];
    }

    /**
     * @param  class-string  $class
     * @return array<string, mixed>
     */
    private function type(string $class, string $name, string $type): array
    {
        return match (true) {
            $type === 'string' => ['type' => 'string'],
            $type === 'int' => ['type' => 'integer'],
            $type === 'float' => ['type' => 'number'],
            $type === 'bool' => ['type' => 'boolean'],
            $type === 'array' => $this->arrayType($class, $name),
            class_exists($type) && (is_subclass_of($type, Data::class) || is_subclass_of($type, BackedEnum::class)) => $this->reference($type),
            default => throw new LogicException("{$class}::\${$name} has a type the API generator doesn't know: {$type}."),
        };
    }

    /**
     * `list<X>` or `array<string, X>` from the constructor's `@param`.
     *
     * @param  class-string  $class
     * @return array<string, mixed>
     */
    private function arrayType(string $class, string $name): array
    {
        $reflection = new ReflectionClass($class);
        $doc = (string) $reflection->getConstructor()?->getDocComment();

        if (preg_match('/@param\s+(list|array)<([^>]+)>(?:\|\S+)?\s+\$'.preg_quote($name, '/').'\b/', $doc, $match) !== 1) {
            throw new LogicException("{$class}::\${$name} is an array without a list<…> or array<string, …> @param.");
        }

        $parts = array_map(trim(...), explode(',', $match[2]));

        if ($match[1] === 'list' || count($parts) === 1) {
            return ['type' => 'array', 'items' => $this->type($class, $name, $this->resolve($reflection, $parts[0]))];
        }

        return ['type' => 'object', 'additionalProperties' => $this->type($class, $name, $this->resolve($reflection, $parts[1]))];
    }

    /**
     * A docblock type name as a class name (via the file's imports and
     * namespace) or a scalar.
     *
     * @param  ReflectionClass<object>  $reflection
     */
    private function resolve(ReflectionClass $reflection, string $type): string
    {
        if (in_array($type, ['string', 'int', 'float', 'bool'], true)) {
            return $type;
        }

        $source = File::get((string) $reflection->getFileName());

        if (preg_match('/^use\s+([A-Za-z0-9_\\\\]+\\\\'.preg_quote($type, '/').');$/m', $source, $match) === 1) {
            return $match[1];
        }

        return $reflection->getNamespaceName().'\\'.$type;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function constraints(ReflectionProperty $property, array $schema): array
    {
        $type = $schema['type'] ?? null;
        $result = [];

        foreach ($property->getAttributes() as $attribute) {
            $instance = $attribute->newInstance();
            $argument = $attribute->getArguments()[0] ?? null;

            if ($instance instanceof Regex && is_string($argument)) {
                $result['pattern'] = $this->pattern($argument);
            } elseif (($instance instanceof Max || $instance instanceof Min) && is_numeric($argument)) {
                $bound = $instance instanceof Max ? 'max' : 'min';
                $key = match ($type) {
                    'string' => $bound.'Length',
                    'array' => $bound.'Items',
                    default => $bound === 'max' ? 'maximum' : 'minimum',
                };
                $result[$key] = (int) $argument;
            } elseif ($instance instanceof In && is_array($argument)) {
                $result['enum'] = array_values($argument);
            }
        }

        return $result;
    }

    /**
     * A PCRE literal (`/^…$/D`, `#…#D`) as an ECMA-262 pattern.
     */
    private function pattern(string $regex): string
    {
        $delimiter = $regex[0];
        $end = strrpos($regex, $delimiter);

        return $end === false || $end === 0 ? $regex : substr($regex, 1, $end - 1);
    }

    private function summary(string $doc): ?string
    {
        if ($doc === '') {
            return null;
        }

        $lines = [];

        foreach (preg_split('/\R/', $doc) ?: [] as $line) {
            $line = trim((string) preg_replace('#^\s*(/\*\*|\*/|\*)\s?#', '', $line));

            if (str_starts_with($line, '@')) {
                break;
            }

            $lines[] = $line;
        }

        $text = (string) preg_replace('#\*/\s*$#', '', implode(' ', $lines));
        // Internal cross-references are for maintainers, not API readers.
        $text = (string) preg_replace(['#\s*\((?:[^()]*\s)?docs/[^()]*\)#', '#^\s*docs/\S+(?:\s§[0-9.]+)?:\s*#', '#\{@see\s+([^}]+)\}#'], ['', '', '$1'], $text);
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return $text === '' ? null : $text;
    }
}
