<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\In;
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
     * @param  list<string>|null  $buildEnv
     * @param  list<string>|null  $envReferences
     * @param  list<BuildWarning>|null  $warnings
     * @param  array<string, mixed>|null  $plan
     */
    public function __construct(
        #[In(['dockerfile', 'railpack'])]
        public string $builder,
        #[In(['explicit', 'dockerfile_detected', 'preset', 'railpack'])]
        public string $builderReason,
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
        public ?array $buildEnv = null,
        public ?array $envReferences = null,
        public ?array $warnings = null,
        public ?array $plan = null,
        /** What the launchroom Next.js deployment adapter reported (build tasks of Next ≥ 16.2 apps). */
        public ?NextBuildInfo $next = null,
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
            'buildEnv' => ['nullable', 'array', 'max:1024'],
            'buildEnv.*' => ['string', 'max:256'],
            'envReferences' => ['nullable', 'array', 'max:256'],
            'envReferences.*' => ['string', 'max:256'],
        ];
    }
}
