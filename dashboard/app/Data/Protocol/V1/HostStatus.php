<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * Report.host (protocol/schema/v1/report.json): host facts with the latest
 * periodic health snapshot. `health` is validated here and normalized by
 * App\Support\Servers\HostHealthFacts before it is stored.
 */
final class HostStatus extends Data
{
    /**
     * @param  array<string, mixed>|null|Optional  $health
     * @param  array<string, mixed>|null|Optional  $mesh  the mesh client's state (docs/05 §2), normalized by App\Support\Servers\MeshStatusFacts
     * @param  list<array<string, mixed>>|null|Optional  $links  latest link measurements per peer (docs/05 §2.1), stored by App\Actions\Servers\RecordLinkQuality
     * @param  list<string>|null|Optional  $features  optional protocol features the daemon understands (docs/03 §10)
     * @param  array<string, mixed>|null|Optional  $controlPlane  which dashboard URL launchroomd uses (docs/05 §4.1)
     * @param  array<string, mixed>|null|Optional  $runtime  how Docker runs and what launchroomd can do there (D49), normalized by App\Data\Servers\ServerRuntimeData
     * @param  array<string, mixed>|null|Optional  $registry  whether the server reaches the image registry (D68)
     * @param  array<string, mixed>|null|Optional  $publicAddresses  the server's public IPv4/IPv6 as IP echo services see them (docs/05 §5), stored by RecordReport
     */
    public function __construct(
        #[Min(1), Max(4096)]
        public int $cpus,
        #[Min(0)]
        public int $memoryMb,
        #[Min(0)]
        public int $diskTotalMb,
        #[Min(0)]
        public int $diskFreeMb,
        #[Regex('/^v?[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D')]
        public string $daemonVersion,
        #[Regex('/^[A-Za-z0-9._-]{1,128}$/D')]
        public string|Optional $dockerVersion = new Optional,
        public array|null|Optional $health = new Optional,
        public array|null|Optional $mesh = new Optional,
        public HardwareFacts|null|Optional $hardware = new Optional,
        public array|null|Optional $links = new Optional,
        public array|null|Optional $features = new Optional,
        public array|null|Optional $controlPlane = new Optional,
        public array|null|Optional $runtime = new Optional,
        public array|null|Optional $publicAddresses = new Optional,
        /** Whether the server reaches the image registry it pulls from (D68), stored by RecordReport. */
        public array|null|Optional $registry = new Optional,
    ) {}

    /** `ControlPlaneBaseURL` (protocol/schema/v1/report.json). */
    public const string BASE_URL_PATTERN = '#^https?://(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*|\[[0-9a-f:.]+\])(?::[0-9]{1,5})?(?:/[A-Za-z0-9._~%!$&\'()*+,;=:@/-]*)?$#D';

    /** `ControlPlaneOrigin`: https, a DNS name with at least two labels, optional port. */
    public const string ORIGIN_PATTERN = '#^https://(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?::[1-9][0-9]{0,4})?$#D';

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        $size = ['sometimes', 'integer', 'min:0'];
        $time = ['string', 'regex:'.Report::TIME_PATTERN];

        return [
            'health' => ['nullable', 'array'],
            'health.collectedAt' => ['required_with:health', ...$time],
            'health.dataDisk' => ['nullable', 'array'],
            'health.dataDisk.totalMb' => $size,
            'health.dataDisk.freeMb' => $size,
            'health.dockerDisk' => ['nullable', 'array'],
            'health.dockerDisk.totalMb' => $size,
            'health.dockerDisk.freeMb' => $size,
            'health.memory' => ['nullable', 'array'],
            'health.memory.totalMb' => $size,
            'health.memory.availableMb' => $size,
            'health.load' => ['nullable', 'array'],
            'health.load.load1' => ['sometimes', 'numeric', 'min:0', 'max:1000000'],
            'health.load.load5' => ['sometimes', 'numeric', 'min:0', 'max:1000000'],
            'health.load.load15' => ['sometimes', 'numeric', 'min:0', 'max:1000000'],
            'health.inotify' => ['nullable', 'array'],
            'health.inotify.maxUserWatches' => $size,
            'health.inotify.maxUserInstances' => $size,
            'health.inotify.maxQueuedEvents' => $size,
            'health.networks' => ['nullable', 'array'],
            'health.networks.total' => $size,
            'health.networks.managed' => $size,
            'health.docker' => ['nullable', 'array'],
            'health.docker.imagesMb' => $size,
            'health.docker.imagesReclaimableMb' => $size,
            'health.docker.containersMb' => $size,
            'health.buildCacheMb' => ['nullable', 'integer', 'min:0'],
            'health.clockSkewMs' => ['nullable', 'integer'],
            'health.cleanup' => ['nullable', 'array'],
            'health.cleanup.at' => ['required_with:health.cleanup', ...$time],
            'health.cleanup.trigger' => ['required_with:health.cleanup', 'in:schedule,disk_pressure'],
            'health.cleanup.removedImages' => $size,
            'health.cleanup.removedContainers' => $size,
            'health.cleanup.reclaimedMb' => $size,
            'health.cleanup.error' => ['sometimes', 'string', 'max:1024'],
            'mesh' => ['nullable', 'array'],
            'mesh.state' => ['required_with:mesh', 'in:starting,needs_login,connected,error'],
            'mesh.ipv4' => ['sometimes', 'ipv4'],
            'mesh.ipv6' => ['sometimes', 'ipv6'],
            'mesh.message' => ['sometimes', 'string', 'max:1024'],
            'mesh.checkedAt' => ['required_with:mesh', ...$time],
            'mesh.peers' => ['sometimes', 'nullable', 'array', 'max:256'],
            'mesh.peers.*.ipv4' => ['required', 'ipv4'],
            'mesh.peers.*.online' => ['required', 'boolean'],
            'mesh.peers.*.connection' => ['required', 'in:direct,relay,idle'],
            'mesh.peers.*.relay' => ['sometimes', 'string', 'regex:/^[A-Za-z0-9._-]{1,128}$/D'],
            'mesh.peers.*.lastHandshake' => ['sometimes', 'nullable', ...$time],
            'links' => ['sometimes', 'nullable', 'array', 'max:256'],
            'links.*' => ['array'],
            'links.*.peer' => ['required', 'string', 'regex:/^srv_[0-9A-HJKMNP-TV-Z]{26}$/D'],
            'links.*.measuredAt' => ['required', ...$time],
            'links.*.rttMs' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:60000'],
            'links.*.error' => ['sometimes', 'string', 'max:256'],
            'links.*.throughputMbps' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:400000', 'required_with:links.*.throughputAt'],
            'links.*.throughputAt' => ['sometimes', 'nullable', 'required_with:links.*.throughputMbps', ...$time],
            'features' => ['sometimes', 'nullable', 'array', 'max:64'],
            'features.*' => ['string', 'regex:/^[a-z][A-Za-z0-9]{0,31}$/D'],
            // Which dashboard URL launchroomd uses (docs/05 §4.1), stored by RecordReport.
            'controlPlane' => ['sometimes', 'nullable', 'array'],
            'controlPlane.state' => ['required_with:controlPlane', 'in:configured,adopted,fallback,pending,refused'],
            'controlPlane.configured' => ['sometimes', 'string', 'max:512', 'regex:'.self::BASE_URL_PATTERN],
            'controlPlane.active' => ['sometimes', 'string', 'max:512', 'regex:'.self::BASE_URL_PATTERN],
            'controlPlane.adopted' => ['sometimes', 'string', 'max:260', 'regex:'.self::ORIGIN_PATTERN],
            'controlPlane.message' => ['sometimes', 'string', 'max:512'],
            // Docker mode, dockerd-guard and capabilities (D49), stored by RecordReport.
            // Capability names are checked by grammar only: unknown ones (newer servers) are dropped.
            'runtime' => ['sometimes', 'nullable', 'array'],
            'runtime.docker' => ['required_with:runtime', 'string', 'in:rootful,rootless'],
            'runtime.guard' => ['required_with:runtime', 'boolean:strict'],
            // macOS servers (D53): grammar only, unknown values (newer servers) are dropped.
            'runtime.hostOs' => ['sometimes', 'string', 'regex:/^[a-z][a-z0-9-]{0,31}$/D'],
            'runtime.engine' => ['sometimes', 'string', 'regex:/^[a-z][a-z0-9-]{0,31}$/D'],
            'runtime.buildPlatforms' => ['sometimes', 'nullable', 'array', 'list', 'max:8'],
            'runtime.buildPlatforms.*' => ['string', 'distinct', 'regex:/^[a-z0-9]{1,16}\/[a-z0-9]{1,16}$/D'],
            'runtime.capabilities' => ['sometimes', 'nullable', 'array', 'list', 'max:16'],
            'runtime.capabilities.*' => ['array'],
            'runtime.capabilities.*.name' => ['required', 'string', 'distinct', 'regex:/^[a-z][A-Za-z0-9]{0,31}$/D'],
            'runtime.capabilities.*.state' => ['required', 'string', 'in:full,degraded,unavailable'],
            'runtime.capabilities.*.message' => ['sometimes', 'string', 'max:512'],
            // Public addresses (docs/05 §5), stored by RecordReport after ServerAddress.
            'publicAddresses' => ['sometimes', 'nullable', 'array'],
            'registry' => ['sometimes', 'nullable', 'array'],
            'registry.reachable' => ['required_with:registry', 'boolean'],
            'registry.checkedAt' => ['required_with:registry', ...$time],
            'registry.latencyMs' => ['sometimes', 'integer', 'min:0'],
            'registry.error' => ['sometimes', 'string', 'max:512'],
            'publicAddresses.ipv4' => ['sometimes', 'string', 'ipv4'],
            'publicAddresses.ipv6' => ['sometimes', 'string', 'ipv6'],
            'publicAddresses.detectedAt' => ['required_with:publicAddresses', ...$time],
        ];
    }
}
