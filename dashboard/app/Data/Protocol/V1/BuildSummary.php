<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Enums\Builds\BuilderReason;
use App\Enums\Builds\ChosenBuilder;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * "We detected…" (docs/04 §4, protocol BuildSummary): the resolved builder,
 * what Railpack detected, preset decisions and the runtime contract (port,
 * health path, release command, processes). Names of secrets only, never values.
 */
final class BuildSummary extends Data
{
    /**
     * @param  list<ResolvedPackage>|null  $packages
     * @param  list<AppliedPreset>|null  $presets
     * @param  array<string, string>|null  $runtimeEnv
     * @param  list<ProcessSuggestion>|null  $processes
     * @param  list<string>|null  $releaseCommand
     * @param  list<GeneratedSecret>|null  $generatedSecrets
     * @param  list<string>|null  $buildEnv
     * @param  list<string>|null  $envReferences
     * @param  list<BuildWarning>|null  $warnings
     * @param  list<PhpExtension>|null  $phpExtensions
     * @param  array<string, mixed>|null  $plan
     * @param  list<EnvAlias>|null  $envAliases
     * @param  list<RequiredSecret>|null  $requiredSecrets
     * @param  list<VolumeSuggestion>|null  $volumeSuggestions
     * @param  list<PhpDependencyExtension>|null  $phpDependencyExtensions
     * @param  list<CommandSuggestion>|null  $commands
     */
    public function __construct(
        public ChosenBuilder $builder,
        public BuilderReason $builderReason,
        #[Max(1024)]
        public ?string $dockerfilePath = null,
        #[Max(64)]
        public ?string $railpackVersion = null,
        #[Max(64)]
        public ?string $provider = null,
        #[Max(64)]
        public ?string $framework = null,
        #[Max(64)]
        public ?string $packageManager = null,
        public ?array $packages = null,
        #[Max(4096)]
        public ?string $installCommand = null,
        #[Max(4096)]
        public ?string $buildCommand = null,
        #[Max(4096)]
        public ?string $startCommand = null,
        #[Min(1), Max(65535)]
        public ?int $port = null,
        public ?array $presets = null,
        public ?array $runtimeEnv = null,
        public ?array $processes = null,
        public ?array $releaseCommand = null,
        #[Max(1024)]
        public ?string $healthPath = null,
        /** Runtime secrets created when the service has none (GenerateDeclaredSecrets, D86). */
        public ?array $generatedSecrets = null,
        public ?array $buildEnv = null,
        public ?array $envReferences = null,
        public ?array $warnings = null,
        public ?array $plan = null,
        /** PHP database drivers added to the Railpack plan for connected databases (docs/04 §3.1). */
        public ?array $phpExtensions = null,
        /** What the launchroom Next.js deployment adapter reported (build tasks of Next ≥ 16.2 apps). */
        public ?NextBuildInfo $next = null,
        /** Framework variables set to system values per environment (docs/06 §3.1). */
        public ?array $envAliases = null,
        /** Secrets the app needs that launchroom can't generate (asked in the plan preview). */
        public ?array $requiredSecrets = null,
        /** Folders whose files should outlive a deploy. */
        public ?array $volumeSuggestions = null,
        /** The monorepo workspace the app belongs to. */
        public ?WorkspaceInfo $workspace = null,
        /** PHP extensions added because composer dependencies require them. */
        public ?array $phpDependencyExtensions = null,
        /** The built image's user and exposed ports. */
        public ?ImageFacts $image = null,
        /** The framework's standard one-off commands (D105). */
        public ?array $commands = null,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'runtimeEnv' => ['nullable', 'array', 'max:64'],
            'runtimeEnv.*' => ['string', 'max:4096'],
            'releaseCommand' => ['nullable', 'array', 'min:1', 'max:64'],
            'releaseCommand.*' => ['string', 'max:4096'],
            'generatedSecrets' => ['nullable', 'array', 'max:8'],
            'buildEnv' => ['nullable', 'array', 'max:1024'],
            'buildEnv.*' => ['string', 'max:256'],
            'envReferences' => ['nullable', 'array', 'max:256'],
            'envReferences.*' => ['string', 'max:256'],
            'phpExtensions' => ['nullable', 'array', 'max:8'],
            'envAliases' => ['nullable', 'array', 'max:16'],
            'requiredSecrets' => ['nullable', 'array', 'max:8'],
            'volumeSuggestions' => ['nullable', 'array', 'max:8'],
            'phpDependencyExtensions' => ['nullable', 'array', 'max:32'],
            'commands' => ['nullable', 'array', 'max:16'],
        ];
    }
}
