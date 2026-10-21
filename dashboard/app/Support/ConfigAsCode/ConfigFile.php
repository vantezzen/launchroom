<?php

declare(strict_types=1);

namespace App\Support\ConfigAsCode;

/**
 * A launchroom.json that passed the schema (docs/18 §2): its services and
 * environment overrides as arrays, plus the document for positions.
 */
final readonly class ConfigFile
{
    /** Overrides for every preview environment (docs/18 §2.3). */
    public const string PREVIEWS = 'previews';

    /** Settings that merge key by key in an override; everything else replaces. */
    private const array MERGED = ['build', 'deploy', 'healthcheck', 'scaling', 'placement', 'labels', 'variables'];

    /**
     * @param  array<string, array<string, mixed>>  $services  slug → service
     * @param  array<string, array<string, array<string, mixed>>>  $overrides  environment slug → service slug → override
     */
    public function __construct(
        public JsonDocument $document,
        public array $services,
        public array $overrides,
    ) {}

    public static function fromDocument(JsonDocument $document): self
    {
        $data = $document->toArray();
        $data = is_array($data) ? $data : [];
        $services = [];
        $overrides = [];

        foreach (Arrays::assoc($data['services'] ?? null) as $slug => $service) {
            $services[$slug] = Arrays::assoc($service);
        }

        foreach (Arrays::assoc($data['environments'] ?? null) as $environment => $override) {
            foreach (Arrays::assoc(Arrays::assoc($override)['services'] ?? null) as $slug => $service) {
                $overrides[$environment][$slug] = Arrays::assoc($service);
            }
        }

        return new self($document, $services, $overrides);
    }

    /**
     * The services as they apply to one environment: base ⊕ override.
     *
     * @return array<string, EffectiveService>
     */
    public function forEnvironment(string $environmentSlug, bool $preview): array
    {
        $key = $preview ? self::PREVIEWS : $environmentSlug;
        $out = [];

        foreach ($this->services as $slug => $service) {
            $override = $this->overrides[$key][$slug] ?? null;
            $out[$slug] = new EffectiveService(
                slug: $slug,
                config: $override === null ? $service : self::merge($service, $override),
                pointer: JsonDocument::pointer('services', $slug),
                overridePointer: $override === null ? null : JsonDocument::pointer('environments', $key, 'services', $slug),
                override: $override ?? [],
            );
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    public static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if ($value === null) {
                unset($base[$key]);

                continue;
            }

            if (in_array($key, self::MERGED, true) && is_array($value) && is_array($base[$key] ?? null)) {
                /** @var array<string, mixed> $nested */
                $nested = $base[$key];
                /** @var array<string, mixed> $value */
                $base[$key] = self::merge($nested, $value);

                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    public function line(string $pointer): int
    {
        return $this->document->nearest($pointer, key: true)['line'];
    }
}
