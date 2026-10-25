<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Deployments\DeployEnvironment;
use App\Contracts\DnsResolver;
use App\Data\Audit\AuditActor;
use App\Data\Servers\HostFactsData;
use App\Enums\DaemonFeature;
use App\Enums\DomainKind;
use App\Enums\Security\InventorySource;
use App\Enums\Security\InventoryStatus;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Placement;
use App\Models\Project;
use App\Models\Security\ImageInventory;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Support\Security\OsvPackage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builders for the security center's feature tests (docs/22): a live
 * service with an image, inventories with components, advisory records,
 * query matches and KEV entries. Public DNS answers for the feed hosts
 * keep SafeHttpClient off the network; responses come from Http::fake().
 */
final class Security
{
    public const string DIGEST = 'sha256:4b5e57f6eb2f42b9039b3d1e13929295f231749c510cbe341cd68036d9af97e2';

    public static function fakeDns(): void
    {
        app()->instance(DnsResolver::class, new class implements DnsResolver
        {
            public function resolve(string $hostname): array
            {
                return in_array($hostname, ['api.osv.dev', 'www.cisa.gov', 'endoflife.date', 'hooks.slack.com'], true) ? ['93.184.216.34'] : [];
            }
        });
    }

    /**
     * An online edge+runtime+build server of $team (arm64) that announces
     * the security center's features.
     *
     * @param  list<DaemonFeature>  $features
     */
    public static function server(Team $team, string $name = 'fra-1', array $features = [DaemonFeature::Waf, DaemonFeature::Inventory, DaemonFeature::ContainerInspection]): Server
    {
        [$server] = Projects::server($team);
        $server->forceFill([
            'name' => $name,
            'facts' => new HostFactsData('host', 'linux', 'arm64', 4, 8192),
            'daemon_features' => array_map(static fn (DaemonFeature $f): string => $f->value, $features),
        ])->save();

        return $server->refresh();
    }

    /**
     * An image service of $project deployed to production and live, with a
     * public custom domain unless $public is false.
     *
     * @return array{Service, ServiceInstance, Deployment}
     */
    public static function liveService(Project $project, string $name = 'web', bool $public = true, ?string $image = null): array
    {
        $service = Projects::image($project, $name);
        resolve(DeployEnvironment::class)->handle($project->production()->sole(), null, AuditActor::system());
        $instance = ServiceInstance::query()->where('service_id', $service->id)->sole();
        $placement = Placement::query()->where('service_instance_id', $instance->id)->latest('id')->firstOrFail();
        $deployment = Deployment::query()->whereKey($placement->deployment_id)->firstOrFail();
        $deployment->forceFill(['image' => $image ?? '127.0.0.1:5000/mirror/docker.io/library/'.$name.'@'.self::DIGEST])->save();
        $instance->forceFill(['active_deployment_id' => $deployment->id])->save();

        if ($public) {
            Domain::query()->create([
                'team_id' => $project->team_id,
                'service_instance_id' => $instance->id,
                'hostname' => $name.'.example.com',
                'kind' => DomainKind::Custom,
            ]);
        }

        return [$service, $instance->refresh(), $deployment->refresh()];
    }

    /**
     * A ready inventory of $deployment's image with $components, linked.
     *
     * @param  list<array<string, mixed>>  $components
     * @param  array{id: string, versionId?: string}|null  $distro
     */
    public static function inventory(Deployment $deployment, array $components, InventorySource $source = InventorySource::Built, ?array $distro = ['id' => 'debian', 'versionId' => '12']): ImageInventory
    {
        $inventory = ImageInventory::query()->create([
            'team_id' => $deployment->team_id,
            'image' => (string) $deployment->image,
            'platform' => 'linux/arm64',
            'source' => $source,
        ]);
        $inventory->forceFill([
            'distro' => $distro,
            'component_count' => count($components),
            'scanned_at' => now(),
            'lockfiles' => $source === InventorySource::Built ? [['path' => 'pnpm-lock.yaml', 'kind' => 'pnpm', 'packages' => 800]] : [],
            'source_analyzed' => $source === InventorySource::Built,
        ]);
        $inventory->transitionTo(InventoryStatus::Ready);

        self::components($inventory, $components, $distro);
        Deployment::query()->where('team_id', $deployment->team_id)->where('image', $deployment->image)->update(['inventory_id' => $inventory->id]);

        return $inventory;
    }

    /**
     * @param  list<array<string, mixed>>  $components
     * @param  array{id: string, versionId?: string}|null  $distro
     */
    public static function components(ImageInventory $inventory, array $components, ?array $distro = null): void
    {
        foreach ($components as $c) {
            $c = [...self::component('npm', 'lodash', '4.17.20'), ...$c];
            $osv = OsvPackage::for((string) $c['ecosystem'], (string) $c['name'], (string) $c['version'], is_string($c['purl'] ?? null) ? $c['purl'] : null, $distro);
            DB::table('inventory_components')->insert([
                'inventory_id' => $inventory->id,
                ...$c,
                'osv_ecosystem' => $osv['ecosystem'] ?? null,
                'osv_name' => $osv['name'] ?? null,
                'osv_version' => $osv['version'] ?? null,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function component(string $ecosystem, string $name, string $version, array $extra = []): array
    {
        $os = in_array($ecosystem, ['deb', 'apk', 'rpm'], true);

        return [
            'ecosystem' => $ecosystem,
            'name' => $name,
            'version' => $version,
            'purl' => null,
            'kind' => $os ? 'os' : ($ecosystem === 'binary' ? 'runtime' : 'library'),
            'scope' => 'runtime',
            'presence' => 'installed',
            'direct' => ! $os && $ecosystem !== 'binary',
            ...$extra,
        ];
    }

    /**
     * An OSV record in `advisories`.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function advisory(string $id, array $attributes = []): void
    {
        $row = [
            'id' => $id,
            'group_key' => $attributes['group_key'] ?? $id,
            'aliases' => json_encode($attributes['aliases'] ?? [], JSON_THROW_ON_ERROR),
            'upstream' => json_encode([], JSON_THROW_ON_ERROR),
            'cve_ids' => json_encode($attributes['cve_ids'] ?? [], JSON_THROW_ON_ERROR),
            'modified' => '2026-10-01T00:00:00Z',
            'published' => '2026-09-01T00:00:00Z',
            'withdrawn' => $attributes['withdrawn'] ?? null,
            'summary' => $attributes['summary'] ?? 'Command injection in lodash',
            'details' => 'Details.',
            'severity_label' => array_key_exists('severity_label', $attributes) ? $attributes['severity_label'] : 'CRITICAL',
            'cvss' => json_encode($attributes['cvss'] ?? ['v3' => 'CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:H', 'v3Score' => 9.8], JSON_THROW_ON_ERROR),
            'attack_vector' => array_key_exists('attack_vector', $attributes) ? $attributes['attack_vector'] : 'N',
            'cwe_ids' => json_encode($attributes['cwe_ids'] ?? ['CWE-89'], JSON_THROW_ON_ERROR),
            'affected' => json_encode($attributes['affected'] ?? [['ecosystem' => 'npm', 'name' => 'lodash', 'fixed' => ['4.17.21']]], JSON_THROW_ON_ERROR),
            'references' => json_encode([['type' => 'ADVISORY', 'url' => 'https://github.com/advisories/'.$id]], JSON_THROW_ON_ERROR),
            'fetched_at' => now(),
            'matched_at' => now(),
        ];

        DB::table('advisories')->upsert([$row], ['id'], array_keys($row));
    }

    /**
     * A cached OSV answer: these advisories affect this package version.
     *
     * @param  list<string>  $ids
     */
    public static function match(string $ecosystem, string $name, string $version, array $ids): void
    {
        DB::table('advisory_queries')->upsert([[
            'osv_ecosystem' => $ecosystem,
            'osv_name' => $name,
            'version' => $version,
            'advisory_ids' => json_encode($ids, JSON_THROW_ON_ERROR),
            'modified' => '{}',
            'checked_at' => CarbonImmutable::now(),
        ]], ['osv_ecosystem', 'osv_name', 'version'], ['advisory_ids', 'checked_at']);
    }

    public static function kev(string $cve, string $added = '2026-10-03'): void
    {
        DB::table('known_exploited')->insert(['cve_id' => $cve, 'date_added' => $added, 'ransomware' => false]);
    }
}
