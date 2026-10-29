<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use App\Data\Protocol\V1\AppliedPreset;
use App\Data\Protocol\V1\BuildSummary;
use App\Data\Protocol\V1\BuildWarning;
use App\Data\Protocol\V1\NextBuildInfo;
use App\Data\Protocol\V1\PhpExtension;
use App\Data\Protocol\V1\ResolvedPackage;
use Spatie\LaravelData\Data;
use Throwable;

/**
 * The "We detected…" summary of a build plan (docs/04 §4): framework,
 * toolchain, commands, port, preset decisions and the variables the code
 * references. Built from a protocol BuildSummary (a plan task result, or the
 * summary a build stored in `deployments.plan.summary`) with
 * {@see self::fromSummary()}. Names of variables only, never values.
 */
final class DetectedPlanData extends Data
{
    /** Display names of the framework ids presets and Railpack report. */
    private const array FRAMEWORKS = [
        'next' => 'Next.js', 'nextjs' => 'Next.js', 'nuxt' => 'Nuxt', 'remix' => 'Remix', 'astro' => 'Astro',
        'vite' => 'Vite', 'sveltekit' => 'SvelteKit', 'svelte' => 'Svelte', 'react-router' => 'React Router',
        'express' => 'Express', 'fastify' => 'Fastify', 'nestjs' => 'NestJS', 'hono' => 'Hono',
        'laravel' => 'Laravel', 'symfony' => 'Symfony', 'django' => 'Django', 'fastapi' => 'FastAPI', 'flask' => 'Flask',
        'rails' => 'Rails', 'spring-boot' => 'Spring Boot', 'static-spa' => 'Static site', 'static' => 'Static site',
        'node' => 'Node.js', 'python' => 'Python', 'php' => 'PHP', 'go' => 'Go', 'ruby' => 'Ruby', 'bun' => 'Bun', 'deno' => 'Deno',
    ];

    /** Variables the platform or runtime provides; never reported as missing. */
    private const array PROVIDED = ['PORT', 'NODE_ENV', 'HOST', 'HOSTNAME', 'HOME', 'PATH', 'PWD', 'TZ', 'CI'];

    /** Toolchains shown as "the runtime" (first match wins). */
    private const array RUNTIMES = [
        'node' => 'Node', 'bun' => 'Bun', 'deno' => 'Deno', 'php' => 'PHP', 'python' => 'Python',
        'ruby' => 'Ruby', 'go' => 'Go', 'java' => 'Java', 'rust' => 'Rust', 'elixir' => 'Elixir',
    ];

    /**
     * @param  list<ResolvedPackage>  $packages  toolchain versions and where each came from
     * @param  list<AppliedPreset>  $presets  presets with their removable decisions
     * @param  list<string>  $buildVariables  variables baked in at build time
     * @param  list<string>  $envReferences  variables the code references
     * @param  list<string>  $missingVariables  referenced in code but not defined
     * @param  list<string>  $warnings
     * @param  array<string, mixed>|null  $planJson  Railpack's build plan (View plan JSON)
     * @param  list<string>  $phpExtensions
     */
    public function __construct(
        /** `dockerfile` or `railpack`. */
        public ?string $builder = null,
        /** `explicit`, `dockerfile_detected`, `preset` or `railpack`. */
        public ?string $builderReason = null,
        /** Display name: "Next.js". */
        public ?string $framework = null,
        public ?string $frameworkVersion = null,
        /** Railpack provider ("node", "php"). */
        public ?string $provider = null,
        /** "pnpm 9.12" (with the version when Railpack resolved one). */
        public ?string $packageManager = null,
        /** "Node 22.11". */
        public ?string $runtime = null,
        /** Where the runtime version came from (".nvmrc"). */
        public ?string $runtimeSource = null,
        public array $packages = [],
        public ?string $installCommand = null,
        public ?string $buildCommand = null,
        public ?string $startCommand = null,
        public ?int $port = null,
        public ?string $healthPath = null,
        public ?string $releaseCommand = null,
        public ?string $railpackVersion = null,
        public array $presets = [],
        public array $buildVariables = [],
        public int $runtimeVariableCount = 0,
        public array $envReferences = [],
        public array $missingVariables = [],
        public array $warnings = [],
        public ?array $planJson = null,
        /** The Next.js adapter's build report (builds only; plan previews don't run the build). */
        public ?NextBuildInfo $next = null,
        /** PHP database drivers launchroom added for connected databases: "pdo_pgsql (for postgres)" (docs/04 §3.1). */
        public array $phpExtensions = [],
    ) {}

    /**
     * @param  list<string>|null  $definedKeys  variable keys this service sees; null when unknown (no service yet)
     */
    public static function fromSummary(BuildSummary $summary, ?array $definedKeys = null): self
    {
        $packages = $summary->packages ?? [];
        $runtime = null;

        foreach (self::RUNTIMES as $name => $label) {
            foreach ($packages as $package) {
                if ($package->name === $name) {
                    $runtime = [$label.' '.$package->version, $package->source];

                    break 2;
                }
            }
        }

        $packageManager = $summary->packageManager;

        if ($packageManager !== null) {
            foreach ($packages as $package) {
                if ($package->name === $packageManager) {
                    $packageManager .= ' '.$package->version;

                    break;
                }
            }
        }

        $references = self::names($summary->envReferences ?? []);
        $buildVariables = self::names($summary->buildEnv ?? []);

        return new self(
            builder: $summary->builder,
            builderReason: $summary->builderReason,
            framework: $summary->framework === null || isset(self::RUNTIMES[strtolower($summary->framework)]) ? null : self::frameworkLabel($summary->framework),
            // "node" or "python" is the runtime, already in the headline.
            frameworkVersion: $summary->next?->nextVersion,
            provider: $summary->provider,
            packageManager: $packageManager,
            runtime: $runtime[0] ?? null,
            runtimeSource: self::source($runtime[1] ?? null),
            packages: $packages,
            installCommand: self::text($summary->installCommand),
            buildCommand: self::text($summary->buildCommand),
            startCommand: self::text($summary->startCommand),
            port: $summary->port,
            healthPath: $summary->healthPath,
            releaseCommand: $summary->releaseCommand === null ? null : self::text(implode(' ', $summary->releaseCommand)),
            railpackVersion: $summary->railpackVersion,
            presets: $summary->presets ?? [],
            buildVariables: $buildVariables,
            runtimeVariableCount: count($summary->runtimeEnv ?? []),
            envReferences: $references,
            missingVariables: $definedKeys === null ? [] : array_values(array_filter(
                array_diff($references, $definedKeys, self::PROVIDED, array_keys($summary->runtimeEnv ?? [])),
                static fn (string $name): bool => ! str_starts_with($name, 'LAUNCHROOM_'),
            )),
            warnings: array_map(static fn (BuildWarning $warning): string => $warning->message, $summary->warnings ?? []),
            planJson: $summary->plan,
            next: $summary->next,
            phpExtensions: array_map(static fn (PhpExtension $extension): string => "{$extension->name} (for {$extension->for})", $summary->phpExtensions ?? []),
        );
    }

    /**
     * "Next.js" for the framework id `next`; unknown ids as reported.
     */
    public static function frameworkLabel(string $framework): string
    {
        return self::FRAMEWORKS[strtolower($framework)] ?? mb_substr($framework, 0, 64);
    }

    /**
     * A stored summary (`service_instances.plan.summary`,
     * `deployments.plan.summary`); null when absent or malformed, so the UI
     * degrades instead of failing when the protocol grows.
     *
     * @param  list<string>|null  $definedKeys
     */
    public static function fromStored(mixed $summary, ?array $definedKeys = null): ?self
    {
        if (! is_array($summary) || $summary === []) {
            return null;
        }

        try {
            return self::fromSummary(BuildSummary::from($summary), $definedKeys);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The Next.js build report of a stored build summary
     * (`deployments.plan.summary.next`); null when absent or malformed.
     */
    public static function nextFromStored(mixed $summary): ?NextBuildInfo
    {
        if (! is_array($summary) || ! is_array($summary['next'] ?? null)) {
            return null;
        }

        try {
            return NextBuildInfo::from($summary['next']);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<mixed>  $names
     * @return list<string>
     */
    private static function names(array $names): array
    {
        $names = array_values(array_unique(array_filter($names, static fn (mixed $name): bool => is_string($name) && preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,255}$/D', $name) === 1)));
        sort($names);

        return array_slice($names, 0, 256);
    }

    private static function text(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : mb_substr($value, 0, 4096);
    }

    /**
     * Railpack's version sources ("package.json > engines > node",
     * "RAILPACK_NODE_VERSION", "railpack default").
     */
    private static function source(?string $source): ?string
    {
        return $source === null || $source === '' ? null : mb_substr($source, 0, 256);
    }
}
