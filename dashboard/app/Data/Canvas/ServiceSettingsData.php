<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use App\Data\ConfigAsCode\ManagedByFileData;
use App\Data\Dockerfiles\DockerfileGenerationPanelData;
use App\Data\Services\BuildSettings;
use App\Data\Services\DeploySettings;
use App\Data\Services\ScalingSettings;
use Spatie\LaravelData\Data;

/**
 * Everything the Settings tab of a service panel shows (docs/08 §3.7).
 */
final class ServiceSettingsData extends Data
{
    /**
     * @param  list<DomainData>  $domains
     * @param  list<VolumeData>  $volumes
     * @param  list<EdgeServerData>  $edges
     */
    public function __construct(
        public ServiceSourceData $source,
        /** Detected build plan: the latest plan preview, else the summary of the latest build. */
        public ?DetectedPlanData $detected,
        /** Plan preview state (app services), null before the first plan. */
        public ?PlanStatusData $plan,
        /** Railpack version launchroom pins when the service doesn't. */
        public string $defaultRailpackVersion,
        public BuildSettings $build,
        public DeploySettings $deploy,
        public ScalingSettings $scaling,
        public bool $enabled,
        public array $domains,
        /** Hostname on the environment network (the service slug). */
        public string $privateHost,
        public array $volumes,
        public AdvancedServiceData $advanced,
        /** The platform's apps domain or sslip.io fallback in use, for the DNS hint. */
        public ?string $appsDomain,
        /** Servers against the placement constraints, and any placement issue. */
        public ?ServicePlacementData $placement = null,
        /** Servers that route this service's domains (every edge of the team). */
        public array $edges = [],
        /** Settings that come from launchroom.json (docs/18 §3.5); null: none. */
        public ?ManagedByFileData $configFile = null,
        /** "Generate a Dockerfile" (app services, docs/20 §7). */
        public ?DockerfileGenerationPanelData $dockerfileGeneration = null,
    ) {}
}
