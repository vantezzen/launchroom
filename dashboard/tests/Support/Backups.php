<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Backups\CreateBackupDestination;
use App\Actions\Deployments\DeployEnvironment;
use App\Contracts\DnsResolver;
use App\Data\Audit\AuditActor;
use App\Data\Backups\BackupDestinationInput;
use App\Models\Backups\BackupDestination;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\User;
use RuntimeException;

/**
 * Builders for backup feature tests: fake DNS, destinations, a running database.
 */
final class Backups
{
    /**
     * Public DNS answers for the endpoints the tests use; never real lookups.
     * Also points restic at a path that doesn't exist, so a test can never
     * reach a real S3 endpoint (destination tests then fail fast and visibly).
     */
    public static function fakeEnvironment(): void
    {
        config(['launchroom.backups.restic_binary' => '/nonexistent/restic']);

        app()->instance(DnsResolver::class, new class implements DnsResolver
        {
            public function resolve(string $hostname): array
            {
                return match ($hostname) {
                    's3.example.com', 'abc.r2.cloudflarestorage.com', 'fsn1.your-objectstorage.com' => ['93.184.216.34'],
                    'internal.example.com' => ['10.0.0.5'],
                    default => [],
                };
            }
        });
    }

    public static function destination(?Team $team, User $user, string $name = 'Primary', bool $makeDefault = false): BackupDestination
    {
        return resolve(CreateBackupDestination::class)->handle($team, BackupDestinationInput::from([
            'name' => $name,
            'endpoint' => 'https://s3.example.com',
            'bucket' => 'launchroom-backups',
            'region' => 'us-east-1',
            'prefix' => 'team-a',
            'accessKeyId' => 'AKIATESTKEY000001',
            'secretAccessKey' => 'super-secret-access-key-value',
            'makeDefault' => $makeDefault,
        ]), $user, AuditActor::user($user));
    }

    /**
     * A team with a server and a deployed, healthy Postgres in production.
     *
     * @return array{Team, Server, string, ServiceInstance}
     */
    public static function runningPostgres(?Team $team = null, string $engine = 'postgres', string $version = '18'): array
    {
        $team ??= Team::factory()->create();
        [$server, $keypair] = Projects::server($team);
        $project = Projects::create($team, 'shop-'.bin2hex(random_bytes(3)));
        $postgres = $engine === 'postgres' ? Projects::postgres($project) : Projects::database($project, $engine, $version);
        $environment = $project->production()->sole();

        resolve(DeployEnvironment::class)->handle($environment, null, AuditActor::system());
        Projects::report($server);
        Projects::report($server);

        $instance = ServiceInstance::query()->where('service_id', $postgres->id)->where('environment_id', $environment->id)->sole();

        if ($instance->active_deployment_id === null) {
            throw new RuntimeException('The test database did not become active.');
        }

        return [$team, $server, $keypair, $instance];
    }

    /**
     * Opens a sealed TaskEnv (protocol task-env.json) with the daemon key pair.
     *
     * @return array{serverId: string, taskId: string, env: array<string, string>}
     */
    public static function openTaskEnv(string $sealed, string $keypair): array
    {
        $plaintext = sodium_crypto_box_seal_open((string) base64_decode($sealed, true), $keypair);

        if ($plaintext === false) {
            throw new RuntimeException('Could not open the sealed task env.');
        }

        /** @var array{serverId: string, taskId: string, env: array<string, string>} $document */
        $document = json_decode($plaintext, true, 8, JSON_THROW_ON_ERROR);

        return $document;
    }
}
