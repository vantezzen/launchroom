<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Connections\CreateConnection;
use App\Actions\Environments\CreateEnvironment;
use App\Actions\Projects\CreateProject;
use App\Actions\Services\CreateService;
use App\Actions\Variables\CreateVariable;
use App\Data\Audit\AuditActor;
use App\Data\Environments\CreateEnvironmentData;
use App\Data\Projects\CreateProjectData;
use App\Data\Services\CreateServiceData;
use App\Data\Specs\EnvironmentSpec;
use App\Data\Specs\ServiceSpec;
use App\Data\Variables\VariableInput;
use App\Data\Variables\VariableTargetData;
use App\Enums\ConnectionMode;
use App\Enums\ConnectionStyle;
use App\Enums\DeploymentFailureClass;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentStepName;
use App\Enums\DeploymentStepStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\LogSourceKind;
use App\Enums\ServiceKind;
use App\Enums\VariableType;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\DeploymentStep;
use App\Models\Environment;
use App\Models\EnvironmentRevision;
use App\Models\LogChunk;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\User;
use App\Queries\CurrentEnvironmentSpec;
use App\Services\Deployments\DeploymentFactory;
use App\Services\Variables\SpecVariableResolver;
use App\Support\PublicId;
use App\Support\Variables\ResolvedEnvironment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * LOCAL DEV ONLY: a project "shop" with a week of
 * deployments in production and staging (a failed build with a known
 * lockfile error, a port mismatch, a rollback, an in-progress deploy),
 * build logs, an hour of runtime logs, per-service usage samples and
 * staging changes to promote. For building and screenshotting the
 * deployments, deployment detail, logs, metrics, promote and activity
 * pages. Writes history directly (no daemon ran it), never placements, so
 * no server is asked to run anything.
 *
 *     make artisan ARGS="db:seed --class=ObserveDemoSeeder"
 */
final class ObserveDemoSeeder extends Seeder
{
    private const string DIGEST = 'sha256:4b5e57f6eb2f42b9039b3d1e13929295f231749c510cbe341cd68036d9af97e2';

    private CarbonImmutable $now;

    private int $seed = 7;

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('ObserveDemoSeeder only runs in the local environment.');
        }

        DB::transaction(fn () => $this->seed());
    }

    private function seed(): void
    {
        $this->now = CarbonImmutable::now();
        $team = Team::query()->orderBy('id')->firstOrFail();
        $server = Server::query()->where('team_id', $team->id)->orderBy('id')->firstOrFail();
        $owner = User::query()->where('email', 'uifin-owner@example.test')->first() ?? User::query()->orderBy('id')->firstOrFail();
        $actor = AuditActor::system();

        if (Project::query()->where('team_id', $team->id)->where('name', 'shop')->exists()) {
            throw new RuntimeException('A project "shop" exists already. Delete it first.');
        }

        $project = resolve(CreateProject::class)->handle($team, new CreateProjectData('shop'), AuditActor::user($owner));
        $production = $project->production()->sole();

        $create = resolve(CreateService::class);
        $postgres = $create->handle($project, CreateServiceData::from([
            'name' => 'postgres', 'kind' => ServiceKind::Database,
            'source' => ['type' => 'database', 'engine' => 'postgres', 'version' => '18'],
        ]), null, $actor);
        $api = $create->handle($project, CreateServiceData::from([
            'name' => 'api', 'kind' => ServiceKind::Image,
            'source' => ['type' => 'image', 'reference' => 'ghcr.io/acme/shop-api@'.self::DIGEST], 'port' => 8080,
        ]), null, $actor);
        $web = $create->handle($project, CreateServiceData::from([
            'name' => 'web', 'kind' => ServiceKind::Image,
            'source' => ['type' => 'image', 'reference' => 'ghcr.io/acme/shop-web@'.self::DIGEST], 'port' => 3000,
        ]), null, $actor);
        $worker = $create->handle($project, CreateServiceData::from([
            'name' => 'worker', 'kind' => ServiceKind::Image,
            'source' => ['type' => 'image', 'reference' => 'ghcr.io/acme/shop-api@'.self::DIGEST],
        ]), null, $actor);

        resolve(CreateConnection::class)->handle($api, $postgres, ConnectionMode::Private, ConnectionStyle::Url, null, $actor);
        resolve(CreateConnection::class)->handle($worker, $postgres, ConnectionMode::Private, ConnectionStyle::Url, null, $actor);

        $this->variable($project, $web, 'APP_NAME', 'Acme Shop', VariableType::Config, [$production]);
        $this->variable($project, $web, 'STRIPE_SECRET_KEY', 'sk_live_51Hx'.str_repeat('a1B2', 6), VariableType::Secret, [$production]);
        $this->variable($project, $api, 'LOG_LEVEL', 'info', VariableType::Config, [$production]);
        $this->variable($project, $api, 'QUEUE_CONNECTION', 'database', VariableType::Config, [$production]);

        $staging = resolve(CreateEnvironment::class)->handle($project, new CreateEnvironmentData('staging'), $owner, AuditActor::user($owner));

        $resolver = resolve(SpecVariableResolver::class);
        $productionSpec = resolve(CurrentEnvironmentSpec::class)->get($production);
        $stagingSpec = resolve(CurrentEnvironmentSpec::class)->get($staging);
        $prodRevision = $this->revision($production, $productionSpec, 1, 'Initial deploy', 7 * 1440);
        $stagingRevision = $this->revision($staging, $stagingSpec, 1, 'Created from production', 3 * 1440);
        $prodEnvs = $resolver->resolveAll($project, $productionSpec);
        $stagingEnvs = $resolver->resolveAll($project, $stagingSpec);

        $commits = [
            ['9a1b2c3d4e5f60718293a4b5c6d7e8f901234567', 'feat: product search with filters', 'mara'],
            ['1f0c2d9a7b3e4f5061728394a5b6c7d8e9f00112', 'feat: order export endpoint', 'mara'],
            ['5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c2d3e4f', 'fix: cart badge count after login', 'jonas'],
            ['2b3c4d5e6f708192a3b4c5d6e7f8091a2b3c4d5e', 'chore: upgrade postgres client', 'bennett'],
            ['7c8d9e0f1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d', 'perf: cache category tree', 'mara'],
            ['8c41e0f2d3a4b5c6d7e8f90112233445566778899', 'chore: bump next to 16.1', 'jonas'],
            ['3b7d5e1f9a2c4e6f8091a2b3c4d5e6f708192a3b', 'fix: order totals with discounts', 'bennett'],
        ];

        // service, trigger, commit index, status, minutes ago, build seconds, failure
        $history = [
            [$postgres, DeploymentTrigger::Config, null, DeploymentStatus::Active, 7 * 1440, 0, null],
            [$api, DeploymentTrigger::Push, 0, DeploymentStatus::Superseded, 6 * 1440 + 200, 71, null],
            [$web, DeploymentTrigger::Push, 0, DeploymentStatus::Superseded, 6 * 1440 + 199, 96, null],
            [$worker, DeploymentTrigger::Push, 0, DeploymentStatus::Superseded, 6 * 1440 + 198, 0, null],
            [$api, DeploymentTrigger::Push, 1, DeploymentStatus::Superseded, 5 * 1440 + 30, 68, null],
            [$worker, DeploymentTrigger::Push, 1, DeploymentStatus::Superseded, 5 * 1440 + 29, 0, null],
            [$web, DeploymentTrigger::Push, 2, DeploymentStatus::Superseded, 4 * 1440 + 400, 101, null],
            [$web, DeploymentTrigger::Manual, 2, DeploymentStatus::Superseded, 3 * 1440 + 90, 0, null],
            [$api, DeploymentTrigger::Push, 3, DeploymentStatus::Superseded, 2 * 1440 + 300, 74, null],
            [$worker, DeploymentTrigger::Push, 3, DeploymentStatus::Superseded, 2 * 1440 + 299, 0, null],
            [$api, DeploymentTrigger::Rollback, 1, DeploymentStatus::Superseded, 2 * 1440 + 120, 0, null],
            [$web, DeploymentTrigger::Push, 4, DeploymentStatus::Superseded, 1440 + 120, 88, null],
            [$web, DeploymentTrigger::Push, 5, DeploymentStatus::Failed, 190, 41, DeploymentFailureClass::BuildError],
            [$api, DeploymentTrigger::Push, 6, DeploymentStatus::Active, 52, 79, null],
            [$web, DeploymentTrigger::Push, 6, DeploymentStatus::Active, 51, 94, null],
            [$worker, DeploymentTrigger::Push, 6, DeploymentStatus::Active, 50, 0, null],
        ];

        $active = [];

        foreach ($history as [$service, $trigger, $commit, $status, $ago, $build, $failure]) {
            $deployment = $this->deployment($project, $production, $prodRevision, $productionSpec, $prodEnvs, $server, $service, $trigger, $commit === null ? null : $commits[$commit], $status, $ago, $build, $failure, in_array($trigger, [DeploymentTrigger::Manual, DeploymentTrigger::Rollback], true) ? $owner : null);

            if ($status === DeploymentStatus::Active) {
                $active[] = $deployment;
            }
        }

        // Staging: a port mismatch on api (explained, with a one-click fix) and web deploying now.
        $stagingHistory = [
            [$postgres, DeploymentTrigger::Config, null, DeploymentStatus::Active, 3 * 1440, 0, null],
            [$api, DeploymentTrigger::Push, 6, DeploymentStatus::Active, 3 * 1440 - 20, 77, null],
            [$web, DeploymentTrigger::Push, 6, DeploymentStatus::Active, 3 * 1440 - 21, 92, null],
            [$api, DeploymentTrigger::Push, null, DeploymentStatus::Failed, 35, 72, DeploymentFailureClass::PortMismatch],
            [$web, DeploymentTrigger::Push, null, DeploymentStatus::Building, 1, null, null],
        ];
        $next = ['d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8091a2b3c4d', 'feat: gift cards at checkout', 'mara'];

        foreach ($stagingHistory as [$service, $trigger, $commit, $status, $ago, $build, $failure]) {
            $this->deployment($project, $staging, $stagingRevision, $stagingSpec, $stagingEnvs, $server, $service, $trigger, $commit === null ? ($service === $postgres ? null : $next) : $commits[$commit], $status, $ago, $build, $failure, null);
        }

        $this->runtime($project, $server, $active);

        // Staging is ahead of production: changes to promote.
        $this->variable($project, $web, 'NEXT_PUBLIC_GIFT_CARDS', 'true', VariableType::Config, [$staging]);
        $this->variable($project, $api, 'GIFT_CARD_PROVIDER_KEY', 'gc_test_'.str_repeat('x7Y', 6), VariableType::Secret, [$staging]);
        $this->variable($project, $api, 'CATALOG_CACHE_TTL', '600', VariableType::Config, [$staging]);

        $this->command->info("Seeded {$project->name} ({$project->public_id}): production {$production->public_id}, staging {$staging->public_id}.");
    }

    /**
     * @param  list<Environment>  $environments
     */
    private function variable(Project $project, ?Service $service, string $key, string $value, VariableType $type, array $environments): void
    {
        resolve(CreateVariable::class)->handle($project, $service, VariableInput::from([
            'key' => $key, 'value' => $value, 'type' => $type,
            'targets' => array_map(static fn (Environment $environment): VariableTargetData => VariableTargetData::environment($environment->public_id), $environments),
        ]), null, AuditActor::system());
    }

    private function revision(Environment $environment, EnvironmentSpec $spec, int $number, string $message, int $minutesAgo): EnvironmentRevision
    {
        $revision = EnvironmentRevision::query()->create([
            'team_id' => $environment->team_id,
            'environment_id' => $environment->id,
            'number' => $number,
            'spec' => $spec,
            'message' => $message,
            'created_by' => null,
            'created_at' => $this->now->subMinutes($minutesAgo),
        ]);
        $environment->forceFill(['last_applied_revision_id' => $revision->id])->save();

        return $revision;
    }

    /**
     * @param  array<string, ResolvedEnvironment>  $envs
     * @param  array{0: string, 1: string, 2: string}|null  $commit
     */
    private function deployment(Project $project, Environment $environment, EnvironmentRevision $revision, EnvironmentSpec $spec, array $envs, Server $server, Service $service, DeploymentTrigger $trigger, ?array $commit, DeploymentStatus $status, int $ago, ?int $build, ?DeploymentFailureClass $failure, ?User $user): Deployment
    {
        $instance = ServiceInstance::query()->where('environment_id', $environment->id)->where('service_id', $service->id)->sole();
        $serviceSpec = $spec->service($service->public_id);

        if (! $serviceSpec instanceof ServiceSpec) {
            throw new RuntimeException("No spec for {$service->name}.");
        }

        $sha = $commit[0] ?? null;
        $deployment = resolve(DeploymentFactory::class)->create($project, $instance, $revision, $spec, $serviceSpec, $envs[$service->public_id], $trigger, $user, $sha, $sha === null ? null : 'refs/heads/main');
        $queued = $this->now->subMinutes($ago)->subSeconds($this->random(0, 50));
        $plan = $this->plan($service, $build, $status, $failure);
        $total = array_sum(array_column($plan, 1)) + 3;
        $finished = $status === DeploymentStatus::Building ? null : $queued->addSeconds(3 + $total);

        $deployment->forceFill([
            'commit_message' => $commit[1] ?? null,
            'commit_author' => $commit[2] ?? null,
            'status' => $status,
            'failure_class' => $failure,
            'failure_message' => match ($failure) {
                DeploymentFailureClass::BuildError => 'Build step "install" failed: ERR_PNPM_OUTDATED_LOCKFILE Cannot install with "frozen-lockfile" because pnpm-lock.yaml is not up to date with package.json',
                DeploymentFailureClass::PortMismatch => 'The app listens on 5173, not 8080.',
                default => null,
            },
            'image' => $deployment->image ?? '127.0.0.1:5000/apps/'.$service->imageRepository().'@'.self::DIGEST,
            'build_server_id' => $trigger === DeploymentTrigger::Push ? $server->id : null,
            'queued_at' => $queued,
            'started_at' => $queued->addSeconds(3),
            'finished_at' => $finished,
            'created_at' => $queued,
        ])->save();

        $this->steps($deployment, $plan, $status, $queued->addSeconds(3));
        $this->buildLogs($deployment, $server, $service, $status, $failure, $build);

        if ($status === DeploymentStatus::Active) {
            $instance->forceFill(['active_deployment_id' => $deployment->id])->save();
        }

        return $deployment;
    }

    /**
     * @return list<array{DeploymentStepName, int, string|null}>
     */
    private function plan(Service $service, ?int $build, DeploymentStatus $status, ?DeploymentFailureClass $failure): array
    {
        if ($service->kind === ServiceKind::Database) {
            return [[DeploymentStepName::Rollout, 14, null], [DeploymentStepName::Health, 6, 'TCP 5432 accepted the connection'], [DeploymentStepName::Route, 1, null]];
        }

        $steps = [[DeploymentStepName::Plan, $this->random(2, 4), 'Next.js 16.1 · pnpm 9.12 · Node 22']];

        if ($build !== null && $build > 0) {
            $steps[] = [DeploymentStepName::Build, $build + $this->random(-6, 6), null];
        } elseif ($build === null) {
            $steps[] = [DeploymentStepName::Build, 0, null];
        }

        if ($service->name === 'api') {
            $steps[] = [DeploymentStepName::Release, $this->random(3, 6), 'php artisan migrate --force'];
        }

        if ($status === DeploymentStatus::Failed && $failure === DeploymentFailureClass::BuildError) {
            return $steps;
        }

        $steps[] = [DeploymentStepName::Rollout, $this->random(5, 9), null];
        $steps[] = [DeploymentStepName::Health, $failure === DeploymentFailureClass::PortMismatch ? 64 : $this->random(4, 9), $failure === DeploymentFailureClass::PortMismatch ? 'No answer on 8080: the app listens on 5173' : 'GET /health → 200 after 2 probes'];

        if (! $failure instanceof DeploymentFailureClass) {
            $steps[] = [DeploymentStepName::Route, $this->random(1, 3), null];
        }

        return $steps;
    }

    /**
     * @param  list<array{DeploymentStepName, int, string|null}>  $plan
     */
    private function steps(Deployment $deployment, array $plan, DeploymentStatus $status, CarbonImmutable $cursor): void
    {
        $last = array_key_last($plan);

        foreach ($plan as $index => [$name, $seconds, $message]) {
            $failed = $status === DeploymentStatus::Failed && $index === $last;
            $running = $status === DeploymentStatus::Building && $name === DeploymentStepName::Build;

            DeploymentStep::query()->create([
                'deployment_id' => $deployment->id,
                'name' => $name,
                'status' => $failed ? DeploymentStepStatus::Failed : ($running ? DeploymentStepStatus::Running : DeploymentStepStatus::Succeeded),
                'started_at' => $cursor,
                'finished_at' => $running ? null : $cursor->addSeconds($seconds),
                'meta' => $failed && $name === DeploymentStepName::Build ? ['message' => 'ERR_PNPM_OUTDATED_LOCKFILE'] : ($message === null ? [] : ['message' => $message]),
            ]);

            if ($running) {
                return;
            }

            $cursor = $cursor->addSeconds($seconds);
        }
    }

    private function buildLogs(Deployment $deployment, Server $server, Service $service, DeploymentStatus $status, ?DeploymentFailureClass $failure, ?int $build): void
    {
        if ($service->kind === ServiceKind::Database) {
            $this->chunk($deployment, $server, null, CarbonImmutable::instance($deployment->started_at ?? $this->now), [
                ['rollout', 'Starting postgres 18 with volume /var/lib/postgresql'],
                ['health', 'TCP 5432 accepted the connection'],
            ]);

            return;
        }

        $failedBuild = $failure === DeploymentFailureClass::BuildError;
        $task = DaemonTask::query()->forceCreate([
            'public_id' => PublicId::generate('tsk'),
            'team_id' => $deployment->team_id,
            'server_id' => $server->id,
            'type' => 'build',
            'spec' => '{}',
            'status' => $status === DeploymentStatus::Building ? 'leased' : ($failedBuild ? 'failed' : 'succeeded'),
            'deployment_id' => $deployment->id,
            'result' => $failedBuild ? ['failure' => ['hints' => [['code' => 'pnpm-outdated-lockfile']]]] : null,
            'finished_at' => $deployment->finished_at,
        ]);
        $sha = substr((string) $deployment->commit_sha, 0, 7);
        $lines = [
            ['clone', "\e[2m#1\e[0m Cloning acme/shop at {$sha}"],
            ['clone', 'Fetched 1 commit (depth 1), 2,481 files'],
            ['plan', "\e[1mNext.js 16.1\e[0m (App Router) · pnpm 9.12 · Node 22.11 (from .nvmrc)"],
            ['install', '$ pnpm install --frozen-lockfile'],
        ];

        if ($failedBuild) {
            $lines[] = ['install', 'Lockfile is up to date, resolution step is skipped'];
            $lines[] = ['install', "\e[31m ERR_PNPM_OUTDATED_LOCKFILE \e[39m Cannot install with \"frozen-lockfile\" because pnpm-lock.yaml is not up to date with package.json"];
            $lines[] = ['install', '  Failure reason:'];
            $lines[] = ['install', '  specifiers in the lockfile ({"next":"16.0.3"}) don\'t match specs in package.json ({"next":"16.1.0"})'];
            $lines[] = ['install', "\e[2mprocess \"pnpm install --frozen-lockfile\" did not complete successfully: exit code: 1\e[0m"];
            $this->chunk($deployment, $server, $task, CarbonImmutable::instance($deployment->started_at ?? $this->now), $lines);

            return;
        }

        for ($i = 0; $i < 60; $i++) {
            $lines[] = ['install', sprintf("\e[2mProgress:\e[0m resolved %d, reused %d, downloaded %d, added %d", 400 + $i * 9, 380 + $i * 8, $i, 400 + $i * 9)];
        }

        $lines[] = ['install', "\e[33mWARN\e[39m 2 deprecated subdependencies found: glob@7.2.3, inflight@1.0.6"];
        $lines[] = ['build', '$ pnpm build'];
        $lines[] = ['build', "   \e[1m▲ Next.js 16.1.0\e[0m"];
        $lines[] = ['build', '   Creating an optimized production build ...'];

        if ($status !== DeploymentStatus::Building) {
            $lines[] = ['build', " \e[32m✓\e[39m Compiled successfully in ".max(20, (int) $build - 30).'s'];
            $lines[] = ['build', " \e[32m✓\e[39m Generating static pages (38/38)"];
            $lines[] = ['push', "\e[32mpushed\e[39m sha256:4b5e57f6eb2f… (148.2 MB, 12 layers, 9 reused)"];
        }

        $this->chunk($deployment, $server, $task, CarbonImmutable::instance($deployment->started_at ?? $this->now), $lines);

        if ($failure === DeploymentFailureClass::PortMismatch) {
            $this->chunk($deployment, $server, null, CarbonImmutable::instance($deployment->started_at ?? $this->now)->addSeconds(80), [
                ['rollout', 'Starting 1 replica next to the current version'],
                ['health', "\e[2m> shop-api@1.4.0 start\e[0m"],
                ['health', '  VITE v6.0.3  ready in 412 ms'],
                ['health', '  ➜  Local:   http://0.0.0.0:5173/'],
                ['health', 'GET http://10.0.0.4:8080/health: connection refused (12 probes)'],
                ['health', "\e[31mHealth check failed:\e[39m the app listens on 5173, not 8080"],
                ['health', 'Stopped the new replica; the current version keeps serving'],
            ]);
        } elseif (in_array($status, [DeploymentStatus::Active, DeploymentStatus::Superseded], true)) {
            $this->chunk($deployment, $server, null, CarbonImmutable::instance($deployment->started_at ?? $this->now)->addSeconds((int) $build + 8), [
                ['rollout', 'Starting 1 replica next to the current version'],
                ['health', 'GET /health → 200 (2 probes, 1.6s)'],
                ['route', 'Switched traffic to the new replica; draining the old one for 10s'],
            ]);
        }
    }

    /**
     * An hour of runtime lines for the live services (a "tail" that ran),
     * with a burst of database timeouts around 25 minutes ago, and per-minute
     * usage samples for six hours.
     *
     * @param  list<Deployment>  $active
     */
    private function runtime(Project $project, Server $server, array $active): void
    {
        $messages = [
            'api' => [
                ['GET /v1/products?category=shoes 200 in 18ms'],
                ['GET /v1/cart 200 in 9ms'],
                ['POST /v1/orders 201 in 142ms'],
                ['GET /v1/orders/18342 200 in 22ms'],
                ['Processing job App\\Jobs\\SyncStock'],
                ["\e[33mWARN\e[39m Slow query (812ms): select * from \"products\" where \"category_id\" = ?"],
            ],
            'web' => [
                ['GET / 200 in 41ms'],
                ['GET /products/linen-shirt 200 in 63ms'],
                ['GET /cart 200 in 12ms'],
                ['GET /checkout 200 in 88ms'],
                ["\e[32m✓\e[39m revalidated /products in 214ms"],
            ],
            'worker' => [
                ['Processing job App\\Jobs\\SendOrderConfirmation'],
                ['Processed job App\\Jobs\\SendOrderConfirmation in 312ms'],
                ['Processing job App\\Jobs\\SyncStock'],
                ['Processed job App\\Jobs\\SyncStock in 1.2s'],
            ],
        ];
        $errors = [
            'api' => 'SQLSTATE[08006] connection to server at "postgres" (10.0.0.3), port 5432 failed: timeout expired',
            'worker' => 'Job App\\Jobs\\SyncStock failed: Illuminate\\Http\\Client\\ConnectionException: cURL error 28: Operation timed out after 10000 milliseconds',
        ];

        foreach ($active as $deployment) {
            $deployment->loadMissing('serviceInstance.service');
            $name = $deployment->serviceInstance->service->name;

            if (! isset($messages[$name])) {
                continue;
            }

            $task = DaemonTask::query()->forceCreate([
                'public_id' => PublicId::generate('tsk'),
                'team_id' => $deployment->team_id,
                'server_id' => $server->id,
                'type' => 'logs',
                'spec' => json_encode(['workload' => PublicId::generate('wl'), 'tail' => 200, 'follow' => true]),
                'status' => 'succeeded',
                'deployment_id' => $deployment->id,
                'purpose' => 'tail:seed',
                'finished_at' => $this->now,
            ]);

            $lines = [];
            $start = $this->now->subMinutes(60);

            for ($second = 0; $second < 3600; $second += $this->random(4, 16)) {
                $at = $start->addSeconds($second)->addMilliseconds($this->random(0, 999));
                $burst = $second > 2000 && $second < 2140 || $second > 3000 && $second < 3040;

                if ($burst && isset($errors[$name]) && $this->random(0, 2) > 0) {
                    $lines[] = [$at, $errors[$name], 'stderr'];

                    continue;
                }

                $pick = $messages[$name][$this->random(0, count($messages[$name]) - 1)];
                $lines[] = [$at, $pick[0], str_contains($pick[0], 'WARN') ? 'stderr' : 'stdout'];
            }

            foreach (array_chunk($lines, 40) as $index => $part) {
                LogChunk::query()->create([
                    'team_id' => $deployment->team_id,
                    'server_id' => $server->id,
                    'daemon_task_id' => $task->id,
                    'deployment_id' => null,
                    'source_kind' => LogSourceKind::Task,
                    'source_id' => $task->public_id,
                    'seq' => $index + 1,
                    'lines' => array_map(static fn (array $line): array => [
                        'time' => $line[0]->utc()->format('Y-m-d\TH:i:s.v\Z'),
                        'stream' => $line[2],
                        'text' => $line[1],
                    ], $part),
                    'created_at' => $part[array_key_last($part)][0],
                ]);
            }

            $this->usage($project, $server, $deployment, $name);
        }
    }

    private function usage(Project $project, Server $server, Deployment $deployment, string $name): void
    {
        [$cpu, $mem] = match ($name) {
            'api' => [0.22, 310],
            'web' => [0.12, 240],
            default => [0.06, 150],
        };
        $workload = PublicId::generate('wl');
        $rows = [];
        $from = $this->now->subHours(6)->startOfMinute();

        for ($minute = 0; $minute < 360; $minute++) {
            $at = $from->addMinutes($minute);
            $wave = sin($minute / 22) * 0.35 + ($minute > 330 && $minute < 336 ? 1.2 : 0);
            $rows[] = [
                'workload_id' => $workload,
                'resolution' => 1,
                'at' => $at->toIso8601String(),
                'server_id' => $server->id,
                'service_instance_id' => $deployment->service_instance_id,
                'project_id' => $project->id,
                'replica' => 0,
                'cpu_cores' => round(max(0.01, $cpu * (1 + $wave + $this->random(-10, 10) / 100)), 3),
                'mem_mb' => (int) round($mem + $minute * 0.12 + $this->random(-8, 8)),
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('workload_metric_samples')->insert($chunk);
        }
    }

    /**
     * @param  list<array{0: string|null, 1: string}>  $lines
     */
    private function chunk(Deployment $deployment, Server $server, ?DaemonTask $task, CarbonImmutable $time, array $lines): void
    {
        foreach (array_chunk($lines, 50) as $index => $part) {
            LogChunk::query()->create([
                'team_id' => $deployment->team_id,
                'server_id' => $server->id,
                'daemon_task_id' => $task?->id,
                'deployment_id' => $task instanceof DaemonTask ? null : $deployment->id,
                'source_kind' => $task instanceof DaemonTask ? LogSourceKind::Task : LogSourceKind::Deployment,
                'source_id' => $task instanceof DaemonTask ? $task->public_id : $deployment->public_id,
                'seq' => $index + 1,
                'lines' => array_map(static function (array $line) use (&$time): array {
                    $time = $time->addMilliseconds(420);

                    return [
                        'time' => $time->utc()->format('Y-m-d\TH:i:s.v\Z'),
                        'stream' => str_contains($line[1], 'ERR_') || str_contains($line[1], 'failed') ? 'stderr' : 'stdout',
                        'text' => $line[1],
                        ...($line[0] === null ? [] : ['step' => $line[0]]),
                    ];
                }, $part),
                'created_at' => $time,
            ]);
        }
    }

    /** Deterministic pseudo-random numbers so screenshots are reproducible. */
    private function random(int $min, int $max): int
    {
        $this->seed = ($this->seed * 1103515245 + 12345) % 2147483648;

        return $min + $this->seed % ($max - $min + 1);
    }
}
