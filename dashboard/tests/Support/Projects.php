<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Projects\CreateProject;
use App\Actions\Servers\RecordReport;
use App\Actions\Services\CreateService;
use App\Data\Audit\AuditActor;
use App\Data\Projects\CreateProjectData;
use App\Data\Protocol\V1\Report;
use App\Data\Servers\HostFactsData;
use App\Data\Services\CreateServiceData;
use App\Enums\ServerRole;
use App\Enums\ServiceKind;
use App\Enums\WorkloadRole;
use App\Models\Deployment;
use App\Models\NodeStateVersion;
use App\Models\Placement;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use App\Services\Crypto\Envelope;
use App\Services\Crypto\ProjectKeyring;
use App\Services\Crypto\SecretKey;

/**
 * Builders for projects, services and fake daemon reports in feature tests.
 */
final class Projects
{
    public const string DIGEST = 'sha256:4b5e57f6eb2f42b9039b3d1e13929295f231749c510cbe341cd68036d9af97e2';

    private static int $seq = 0;

    /**
     * A fresh in-memory KEK (tests never touch key files).
     */
    public static function useTestKek(): void
    {
        app()->instance(Envelope::class, new Envelope(SecretKey::generate()));
        app()->forgetScopedInstances();
        app()->forgetInstance(ProjectKeyring::class);
    }

    public static function create(Team $team, string $name = 'Shop'): Project
    {
        return resolve(CreateProject::class)->handle($team, new CreateProjectData($name), AuditActor::system());
    }

    public static function postgres(Project $project, string $name = 'postgres'): Service
    {
        return resolve(CreateService::class)->handle($project, CreateServiceData::from([
            'name' => $name,
            'kind' => ServiceKind::Database,
            'source' => ['type' => 'database', 'engine' => 'postgres', 'version' => '18'],
        ]), null, AuditActor::system());
    }

    public static function database(Project $project, string $engine, string $version, ?string $name = null): Service
    {
        return resolve(CreateService::class)->handle($project, CreateServiceData::from([
            'name' => $name ?? $engine,
            'kind' => ServiceKind::Database,
            'source' => ['type' => 'database', 'engine' => $engine, 'version' => $version],
        ]), null, AuditActor::system());
    }

    public static function image(Project $project, string $name = 'web', ?string $reference = null, ?int $port = 80): Service
    {
        return resolve(CreateService::class)->handle($project, CreateServiceData::from([
            'name' => $name,
            'kind' => ServiceKind::Image,
            'source' => ['type' => 'image', 'reference' => $reference ?? 'nginx@'.self::DIGEST],
            'port' => $port,
        ]), null, AuditActor::system());
    }

    /**
     * An online server whose sealing key pair we know.
     *
     * @param  list<ServerRole>  $roles
     * @return array{Server, string} the server and its X25519 key pair
     */
    public static function server(Team $team, array $roles = [ServerRole::Edge, ServerRole::Runtime, ServerRole::Build], int $memoryMb = 8192, string $ip = '203.0.113.7'): array
    {
        $keypair = sodium_crypto_box_keypair();

        $server = Server::factory()->online()->create([
            'team_id' => $team->id,
            'roles' => $roles,
            'sealing_public_key' => 'x25519:'.base64_encode(sodium_crypto_box_publickey($keypair)),
            'facts' => new HostFactsData('host', 'linux', 'arm64', 4, $memoryMb),
            'public_ipv4' => $ip,
        ]);

        return [$server->refresh(), $keypair];
    }

    /**
     * Sends a full report for $server like the daemon does: serving workloads
     * get $phase (or a per-workload phase), draining ones report "exited"
     * once their drain deadline passed. Applies the latest NodeState version
     * unless $appliedVersion says otherwise.
     *
     * @param  array<string, string>  $phases  workload id => phase
     * @param  array<string, string>  $messages  workload id => message
     * @param  array<string, array{stage: string, downloadedBytes: int, totalBytes: int}>  $pulls  workload id => pull progress
     */
    public static function report(Server $server, string $phase = 'healthy', array $phases = [], ?int $appliedVersion = null, array $messages = [], array $pulls = []): void
    {
        $server->refresh();
        self::$seq++;

        $workloads = Placement::query()->where('server_id', $server->id)->orderBy('id')->get()->map(static fn (Placement $placement): array => [
            ...(isset($messages[$placement->workload_id]) ? ['message' => $messages[$placement->workload_id]] : []),
            ...(isset($pulls[$placement->workload_id]) ? ['pull' => $pulls[$placement->workload_id]] : []),
            'id' => $placement->workload_id,
            'phase' => $phases[$placement->workload_id] ?? ($placement->role === WorkloadRole::Draining && $placement->drain_until?->lessThanOrEqualTo(now()) ? 'exited' : $phase),
            'observedAt' => now()->addMilliseconds(self::$seq)->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'restartCount' => 0,
            'containerId' => str_repeat('c', 64),
        ])->values()->all();

        resolve(RecordReport::class)->handle($server, Report::validateAndCreate([
            'apiVersion' => 'launchroom.vantezzen.io/v1',
            'kind' => 'Report',
            'serverId' => $server->public_id,
            'observedAt' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'seq' => (int) (microtime(true) * 1_000_000) + self::$seq,
            'full' => true,
            'appliedVersion' => $appliedVersion ?? $server->desired_version,
            'workloads' => $workloads,
        ]));
    }

    /**
     * The latest NodeState of $server, decoded.
     *
     * @return array<string, mixed>
     */
    public static function nodeState(Server $server): array
    {
        $latest = NodeStateVersion::query()->where('server_id', $server->id)->orderByDesc('version')->firstOrFail();

        return json_decode($latest->document, true, 64, JSON_THROW_ON_ERROR);
    }

    /**
     * The NodeState routes of domains (without the immutable per-deployment
     * routes `{service}-{shortid}.…`, whose id is the deployment's ULID).
     *
     * @return list<array<string, mixed>>
     */
    public static function domainRoutes(Server $server): array
    {
        return array_values(array_filter(
            self::nodeState($server)['routes'],
            static fn (array $route): bool => ! Deployment::query()->where('public_id', 'dpl_'.substr((string) $route['id'], 3))->exists(),
        ));
    }

    public static function latestDocument(Server $server): string
    {
        return NodeStateVersion::query()->where('server_id', $server->id)->orderByDesc('version')->firstOrFail()->document;
    }

    /**
     * @return array<string, string>
     */
    public static function openEnv(string $sealed, string $keypair): array
    {
        $plaintext = sodium_crypto_box_seal_open((string) base64_decode($sealed, true), $keypair);

        if ($plaintext === false) {
            throw new \RuntimeException('Could not open the sealed env.');
        }

        /** @var array{env: array<string, string>} $document */
        $document = json_decode($plaintext, true, 8, JSON_THROW_ON_ERROR);

        return $document['env'];
    }
}
