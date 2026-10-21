<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Connections\CreateConnection;
use App\Actions\Daemons\CompleteTask;
use App\Actions\Daemons\LeaseTasks;
use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Previews\SyncPullRequest;
use App\Actions\Services\CreateService;
use App\Data\Audit\AuditActor;
use App\Data\Previews\PullRequestData;
use App\Data\Protocol\V1\LeaseRequest;
use App\Data\Protocol\V1\TaskCompletion;
use App\Data\Services\CreateServiceData;
use App\Enums\ConnectionMode;
use App\Enums\ConnectionStyle;
use App\Enums\DaemonTaskType;
use App\Enums\Previews\PreviewProvider;
use App\Enums\ServiceKind;
use App\Models\DaemonTask;
use App\Models\Environment;
use App\Models\Previews\Preview;
use App\Models\Previews\PreviewSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;

/**
 * Builders for preview environment tests: a project with a plain-git app
 * (the dev fixture remote, faked) and Postgres, a fake daemon that builds and
 * runs everything, and pull request events.
 */
final class PreviewFlow
{
    public const string SHA_MAIN = '1111111111111111111111111111111111111111';

    public const string SHA_PR_1 = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public const string SHA_PR_2 = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public static function setUp(): void
    {
        Projects::useTestKek();
        config(['launchroom.git.plain_sources' => true, 'launchroom.git.insecure_hosts' => ['git-fixtures'], 'launchroom.domains.localhost' => true]);

        Http::fake([
            'http://git-fixtures:8080/*' => static fn (): PromiseInterface => Http::response(
                self::pkt("# service=git-upload-pack\n").'0000'.self::pkt(self::SHA_MAIN." HEAD\0multi_ack side-band-64k\n").self::pkt(self::SHA_MAIN." refs/heads/main\n").'0000',
            ),
        ]);
    }

    /**
     * A deployed project: app `web` (plain git) → `postgres`, production live.
     *
     * @return array{Team, Server, string, Project, Environment, Service, Service}
     */
    public static function project(): array
    {
        $team = Team::factory()->create();
        [$server, $keypair] = Projects::server($team);
        $project = Projects::create($team, 'shop');
        $postgres = Projects::postgres($project);
        $web = self::gitApp($project, 'web');
        resolve(CreateConnection::class)->handle($web, $postgres, ConnectionMode::Private, ConnectionStyle::Url, null, AuditActor::system());
        $production = $project->production()->sole();

        resolve(DeployEnvironment::class)->handle($production, null, AuditActor::system());
        self::run($server);

        PreviewSettings::forProject($project)->forceFill(['enabled' => true])->save();

        return [$team, $server, $keypair, $project, $production, $postgres, $web];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public static function gitApp(Project $project, string $name, array $extra = []): Service
    {
        return resolve(CreateService::class)->handle($project, CreateServiceData::from([
            'name' => $name,
            'kind' => ServiceKind::App,
            'source' => ['type' => 'git', 'provider' => 'git', 'repositoryId' => 0, 'fullName' => 'fixtures/node-hello', 'cloneUrl' => 'http://git-fixtures:8080/node-hello.git', 'branch' => 'main'],
            ...$extra,
        ]), null, AuditActor::system());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function event(Project $project, string $action = 'opened', array $overrides = []): Preview
    {
        return resolve(SyncPullRequest::class)->handle($project, new PullRequestData(...[
            'provider' => PreviewProvider::Git,
            'installationId' => null,
            'repositoryId' => 0,
            'repositoryName' => 'fixtures/node-hello',
            'action' => $action,
            'number' => 7,
            'title' => 'Add login',
            'headSha' => self::SHA_PR_1,
            'headRef' => 'feature/login',
            'baseRef' => 'main',
            'isFork' => false,
            'authorLogin' => 'ada',
            'authorPermission' => 'write',
            'merged' => false,
            'labels' => [],
            'changedFiles' => null,
            ...$overrides,
        ]));
    }

    /**
     * Fake daemon: builds every leased build task, then reports every
     * workload healthy, until nothing changes.
     */
    public static function run(Server $server, int $rounds = 6): void
    {
        for ($i = 0; $i < $rounds; $i++) {
            foreach (resolve(LeaseTasks::class)->handle($server->refresh(), LeaseRequest::from(['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'LeaseRequest', 'max' => 16, 'types' => ['build']])) as $task) {
                $row = DaemonTask::query()->where('public_id', $task->id)->sole();

                if ($row->type !== DaemonTaskType::Build) {
                    continue;
                }

                $image = is_object($row->spec) && is_string($row->spec->image ?? null) ? $row->spec->image : '';
                resolve(CompleteTask::class)->handle($server, $row, TaskCompletion::validateAndCreate([
                    'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'TaskCompletion', 'attempt' => $row->attempt,
                    'outcome' => 'succeeded',
                    'build' => [
                        'image' => $image.'@sha256:'.str_repeat(dechex(($row->id % 15) + 1), 64),
                        'durationMs' => 1000,
                        'summary' => ['builder' => 'railpack', 'builderReason' => 'preset', 'railpackVersion' => '0.42.0', 'provider' => 'node', 'port' => 3000],
                        'secretsHash' => str_repeat('e', 64),
                    ],
                ]));
            }

            Projects::report($server);
        }
    }

    private static function pkt(string $line): string
    {
        return sprintf('%04x', strlen($line) + 4).$line;
    }
}
