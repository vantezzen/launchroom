<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use App\Data\Protocol\V1\AppliedPreset;
use App\Data\Protocol\V1\BuildSummary;
use App\Data\Protocol\V1\BuildWarning;
use App\Data\Protocol\V1\EnvAlias;
use App\Data\Protocol\V1\GeneratedSecret;
use App\Data\Protocol\V1\NextBuildInfo;
use App\Data\Protocol\V1\PhpExtension;
use App\Data\Protocol\V1\RequiredSecret;
use App\Data\Protocol\V1\ResolvedPackage;
use App\Data\Protocol\V1\VolumeSuggestion;
use App\Enums\Builds\BuilderReason;
use App\Enums\Builds\ChosenBuilder;
use App\Support\Variables\VariableKey;
use Spatie\LaravelData\Data;

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
        'phoenix' => 'Phoenix', 'java' => 'Java', 'aspnetcore' => 'ASP.NET Core', 'dotnet' => '.NET', 'rocket' => 'Rocket', 'litestar' => 'Litestar',
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
     * @param  list<string>  $generatedSecrets  secrets launchroom creates at deploy when the service has none (D86)
     * @param  list<string>  $warnings
     * @param  array<string, mixed>|null  $planJson  Railpack's build plan (View plan JSON)
     * @param  list<string>  $phpExtensions
     * @param  list<string>  $envAliases  "APP_URL from PUBLIC_URL": framework variables launchroom sets per environment
     * @param  list<RequiredSecret>  $requiredSecrets  secrets the app needs that launchroom can't generate
     * @param  list<string>  $missingSecrets  the required ones the service doesn't have (all of them when unknown)
     * @param  list<VolumeSuggestion>  $volumeSuggestions  folders whose files should outlive a deploy
     */
    public function __construct(
        public ?ChosenBuilder $builder = null,
        public ?BuilderReason $builderReason = null,
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
        public array $generatedSecrets = [],
        public array $warnings = [],
        public ?array $planJson = null,
        /** The Next.js adapter's build report (builds only; plan previews don't run the build). */
        public ?NextBuildInfo $next = null,
        /** PHP database drivers launchroom added for connected databases: "pdo_pgsql (for postgres)" (docs/04 §3.1). */
        public array $phpExtensions = [],
        public array $envAliases = [],
        public array $requiredSecrets = [],
        public array $missingSecrets = [],
        public array $volumeSuggestions = [],
        /** A Laravel app with no database connected runs on a SQLite file that every deploy resets. */
        public ?string $sqliteWarning = null,
    ) {}

    /** Warnings that only matter with more than one replica (G56). */
    public const array MULTI_REPLICA_WARNINGS = ['isr-cache-warning'];

    /** Variables that point a Laravel app at a database other than its default SQLite file. */
    private const array LARAVEL_DATABASE_KEYS = ['DB_CONNECTION', 'DB_URL', 'DATABASE_URL'];

    /**
     * @param  list<string>|null  $definedKeys  variable keys this service sees; null when unknown (no service yet)
     * @param  int  $replicas  the service's replicas (warnings about several replicas are left out at one)
     */
    public static function fromSummary(BuildSummary $summary, ?array $definedKeys = null, int $replicas = 1): self
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
        $generated = self::names(array_map(static fn (GeneratedSecret $secret): string => $secret->name, $summary->generatedSecrets ?? []));
        $buildVariables = self::names($summary->buildEnv ?? []);
        $aliases = $summary->envAliases ?? [];
        $required = array_slice($summary->requiredSecrets ?? [], 0, 8);
        $requiredNames = array_map(static fn (RequiredSecret $secret): string => $secret->name, $required);
        $provided = [...array_map(static fn (EnvAlias $alias): string => $alias->name, $aliases), ...$requiredNames];
        $warnings = array_values(array_filter(
            $summary->warnings ?? [],
            static fn (BuildWarning $warning): bool => $replicas > 1 || ! in_array($warning->code, self::MULTI_REPLICA_WARNINGS, true),
        ));
        $laravel = strtolower((string) $summary->framework) === 'laravel';

        return new self(
            builder: $summary->builder,
            builderReason: $summary->builderReason,
            // "node" or "python" is the runtime, already in the headline.
            framework: $summary->framework === null || isset(self::RUNTIMES[strtolower($summary->framework)]) ? null : self::frameworkLabel($summary->framework),
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
                array_diff($references, $definedKeys, self::PROVIDED, array_keys($summary->runtimeEnv ?? []), $generated, $provided),
                static fn (string $name): bool => ! str_starts_with($name, 'LAUNCHROOM_'),
            )),
            generatedSecrets: $definedKeys === null ? $generated : array_values(array_diff($generated, $definedKeys)),
            warnings: array_map(static fn (BuildWarning $warning): string => $warning->message, $warnings),
            planJson: $summary->plan,
            next: $summary->next,
            phpExtensions: array_map(static fn (PhpExtension $extension): string => "{$extension->name} (for {$extension->for})", $summary->phpExtensions ?? []),
            envAliases: array_map(static fn (EnvAlias $alias): string => "{$alias->name} from {$alias->from->value}", $aliases),
            requiredSecrets: $required,
            missingSecrets: $definedKeys === null ? $requiredNames : array_values(array_diff($requiredNames, $definedKeys)),
            volumeSuggestions: array_slice($summary->volumeSuggestions ?? [], 0, 8),
            sqliteWarning: $laravel && $definedKeys !== null && array_intersect(self::LARAVEL_DATABASE_KEYS, $definedKeys) === []
                ? 'This Laravel app keeps its data in a SQLite file inside the container. Every deploy starts it empty, and pages that use sessions fail. Connect a database, or keep the file on a volume.'
                : null,
        );
    }

    public static function frameworkLabel(string $framework): string
    {
        return self::FRAMEWORKS[strtolower($framework)] ?? mb_substr($framework, 0, 64);
    }

    /**
     * @param  array<mixed>  $names
     * @return list<string>
     */
    private static function names(array $names): array
    {
        $names = array_values(array_unique(array_filter($names, static fn (mixed $name): bool => is_string($name) && VariableKey::isValid($name))));
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
