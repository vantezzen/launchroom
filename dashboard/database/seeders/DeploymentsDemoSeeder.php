<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Connections\CreateConnection;
use App\Actions\Environments\CreateEnvironment;
use App\Actions\Projects\CreateProject;
use App\Actions\Services\CreateService;
use App\Actions\Variables\CreateVariable;
use App\Actions\Variables\SetSharedVariableUsage;
use App\Data\Audit\AuditActor;
use App\Data\Environments\CreateEnvironmentData;
use App\Data\Projects\CreateProjectData;
use App\Data\Services\CreateServiceData;
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
use App\Enums\TeamRole;
use App\Enums\VariableType;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\DeploymentStep;
use App\Models\Environment;
use App\Models\EnvironmentRevision;
use App\Models\LogChunk;
use App\Models\Membership;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\User;
use App\Models\Variable;
use App\Queries\CurrentEnvironmentSpec;
use App\Services\Deployments\DeploymentFactory;
use App\Services\Variables\SpecVariableResolver;
use App\Support\PublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * LOCAL DEV ONLY: realistic deployments, logs, variables and staged changes
 * for building and screenshotting the deployments UI. Writes deployment
 * history directly (no daemon involved), which is why it refuses to run
 * outside the local environment. Never weakens auth: the demo users are
 * ordinary team members with a dev password.
 *
 *     make artisan ARGS="db:seed --class=DeploymentsDemoSeeder"
 */
final class DeploymentsDemoSeeder extends Seeder
{
    private const string DIGEST = 'sha256:4b5e57f6eb2f42b9039b3d1e13929295f231749c510cbe341cd68036d9af97e2';

    private CarbonImmutable $now;

    public function run(
        CreateProject $createProject,
        CreateService $createService,
        CreateConnection $createConnection,
        CreateEnvironment $createEnvironment,
        CurrentEnvironmentSpec $currentSpec,
        SpecVariableResolver $resolver,
        DeploymentFactory $factory,
    ): void {
        if (! app()->environment('local')) {
            throw new RuntimeException('DeploymentsDemoSeeder only runs in the local environment.');
        }

        DB::transaction(fn () => $this->seed($createProject, $createService, $createConnection, $createEnvironment, $currentSpec, $resolver, $factory));
    }

    private function seed(
        CreateProject $createProject,
        CreateService $createService,
        CreateConnection $createConnection,
        CreateEnvironment $createEnvironment,
        CurrentEnvironmentSpec $currentSpec,
        SpecVariableResolver $resolver,
        DeploymentFactory $factory,
    ): void {

        $this->now = CarbonImmutable::now();
        $team = Team::query()->orderBy('id')->firstOrFail();
        $this->members($team);

        $server = Server::query()->where('team_id', $team->id)->orderBy('id')->firstOrFail();
        $actor = AuditActor::system();

        $project = $createProject->handle($team, new CreateProjectData('acme-store'), $actor);
        $production = $project->production()->sole();
        $staging = $createEnvironment->handle($project, new CreateEnvironmentData('staging'), null, $actor);

        $postgres = $createService->handle($project, CreateServiceData::from([
            'name' => 'postgres', 'kind' => ServiceKind::Database,
            'source' => ['type' => 'database', 'engine' => 'postgres', 'version' => '18'],
        ]), null, $actor);
        $api = $createService->handle($project, CreateServiceData::from([
            'name' => 'api', 'kind' => ServiceKind::Image,
            'source' => ['type' => 'image', 'reference' => 'ghcr.io/acme/api@'.self::DIGEST], 'port' => 8080,
        ]), null, $actor);
        $web = $createService->handle($project, CreateServiceData::from([
            'name' => 'web', 'kind' => ServiceKind::Image,
            'source' => ['type' => 'image', 'reference' => 'ghcr.io/acme/storefront@'.self::DIGEST], 'port' => 3000,
        ]), null, $actor);

        $createConnection->handle($api, $postgres, ConnectionMode::Private, ConnectionStyle::Url, null, $actor);

        $this->variable($project, $web, 'NEXT_PUBLIC_API_URL', 'https://${{api.DOMAIN}}', VariableType::Config, [$production]);
        $this->variable($project, $web, 'APP_NAME', 'Acme Store', VariableType::Config, [$production, $staging]);
        $this->variable($project, $web, 'STRIPE_SECRET_KEY', 'sk_live_51Hx'.str_repeat('a1B2', 6), VariableType::Secret, [$production]);
        $this->variable($project, $web, 'SENTRY_AUTH_TOKEN', 'sntrys_'.str_repeat('Zx9', 8), VariableType::Secret, [$production]);
        $this->variable($project, $web, 'API_INTERNAL_URL', '${{api.PRIVATE_URL}}/v1', VariableType::Config, [$production]);
        $this->variable($project, $web, 'SESSION_SECRET', '${{ secret(48) }}', VariableType::Secret, [$production]);
        $this->variable($project, $api, 'LOG_LEVEL', 'info', VariableType::Config, [$production]);
        $this->variable($project, $api, 'QUEUE_CONNECTION', 'database', VariableType::Config, [$production]);
        $sentryOrg = $this->variable($project, null, 'SENTRY_ORG', 'acme', VariableType::Config, [$production]);
        $this->variable($project, null, 'SUPPORT_EMAIL', 'help@acme.test', VariableType::Config, [$production]);
        resolve(SetSharedVariableUsage::class)->handle($sentryOrg, $web, true);

        // Revision 1: what production runs.
        $spec = $currentSpec->get($production);
        $revision = EnvironmentRevision::query()->create([
            'team_id' => $team->id,
            'environment_id' => $production->id,
            'number' => 1,
            'spec' => $spec,
            'message' => 'Initial deploy',
            'created_by' => null,
            'created_at' => $this->now->subDays(3),
        ]);
        $production->forceFill(['last_applied_revision_id' => $revision->id])->save();
        $envs = $resolver->resolveAll($project, $spec);

        /** @var list<array{Service, DeploymentTrigger, string|null, string|null, string|null, DeploymentStatus, int, int|null, DeploymentFailureClass|null}> $history */
        $history = [
            // service, trigger, sha, message, author, status, minutes ago, duration, failure
            [$postgres, DeploymentTrigger::Config, null, null, null, DeploymentStatus::Active, 4300, 31, null],
            [$api, DeploymentTrigger::Push, '1f0c2d9a7b3e4f5061728394a5b6c7d8e9f00112', "feat: order export endpoint\n\nAdds CSV export.", 'mara', DeploymentStatus::Superseded, 2900, 74, null],
            [$web, DeploymentTrigger::Push, '1f0c2d9a7b3e4f5061728394a5b6c7d8e9f00112', "feat: order export endpoint\n\nAdds CSV export.", 'mara', DeploymentStatus::Superseded, 2898, 102, null],
            [$web, DeploymentTrigger::Push, '8c41e0f2d3a4b5c6d7e8f90112233445566778899', 'chore: bump next to 16.1', 'jonas', DeploymentStatus::Failed, 190, 48, DeploymentFailureClass::BuildError],
            [$api, DeploymentTrigger::Push, '3b7d5e1f9a2c4e6f8091a2b3c4d5e6f708192a3b', 'fix: order totals with discounts', 'bennett', DeploymentStatus::Active, 62, 81, null],
            [$web, DeploymentTrigger::Push, '3b7d5e1f9a2c4e6f8091a2b3c4d5e6f708192a3b', 'fix: order totals with discounts', 'bennett', DeploymentStatus::Active, 61, 96, null],
            [$web, DeploymentTrigger::Manual, '3b7d5e1f9a2c4e6f8091a2b3c4d5e6f708192a3b', 'fix: order totals with discounts', 'bennett', DeploymentStatus::Building, 1, null, null],
        ];

        foreach ($history as [$service, $trigger, $sha, $message, $author, $status, $ago, $duration, $failure]) {
            $instance = ServiceInstance::query()->where('environment_id', $production->id)->where('service_id', $service->id)->sole();
            $serviceSpec = $spec->service($service->public_id);

            if (! $serviceSpec instanceof ServiceSpec) {
                continue;
            }

            $deployment = $factory->create($project, $instance, $revision, $spec, $serviceSpec, $envs[$service->public_id], $trigger, null, $sha, $sha === null ? null : 'refs/heads/main');
            $queued = $this->now->subMinutes($ago);
            $finished = $duration === null ? null : $queued->addSeconds($duration + 4);

            // Dev-only history: statuses are written directly (no daemon ran these).
            $deployment->forceFill([
                'commit_message' => $message,
                'commit_author' => $author,
                'status' => $status,
                'failure_class' => $failure,
                'failure_message' => $failure instanceof DeploymentFailureClass ? "Build step \"install\" failed: ERR_PNPM_OUTDATED_LOCKFILE Cannot install with \"frozen-lockfile\" because pnpm-lock.yaml is not up to date with package.json\nRun pnpm install and commit the updated lockfile." : null,
                'image' => $deployment->image ?? '127.0.0.1:5000/apps/'.$service->imageRepository().'@'.self::DIGEST,
                'queued_at' => $queued,
                'started_at' => $queued->addSeconds(4),
                'finished_at' => $finished,
                'created_at' => $queued,
            ])->save();

            $this->steps($deployment, $service, $status, $queued);
            $this->logs($deployment, $server, $service, $status);

            if ($status === DeploymentStatus::Active) {
                $instance->forceFill(['active_deployment_id' => $deployment->id])->save();
            }
        }

        // Staged changes on top of revision 1.
        $this->variable($project, $api, 'QUEUE_WORKERS', '4', VariableType::Config, [$production]);
        $this->variable($project, $web, 'ANALYTICS_URL', '${{analytics.PUBLIC_URL}}/script.js', VariableType::Config, [$production]);
        $this->variable($project, $web, 'NEXT_PUBLIC_STRIPE_KEY', 'pk_live_51Hx'.str_repeat('Q7', 10), VariableType::Config, [$production]);
        $createService->handle($project, CreateServiceData::from([
            'name' => 'mailer', 'kind' => ServiceKind::Image,
            'source' => ['type' => 'image', 'reference' => 'ghcr.io/acme/mailer@'.self::DIGEST],
        ]), null, $actor);

        $this->command->info("Seeded {$project->name} ({$project->public_id}), production {$production->public_id}.");
        $this->command->info('Users: ui2-admin@example.test / ui2-viewer@example.test, password "password".');
    }

    private function members(Team $team): void
    {
        foreach (['ui2-admin@example.test' => TeamRole::Admin, 'ui2-viewer@example.test' => TeamRole::Viewer] as $email => $role) {
            $user = User::query()->firstOrCreate(['email' => $email], [
                'name' => $role === TeamRole::Admin ? 'Ada Admin' : 'Vic Viewer',
                'password' => Hash::make('password'),
            ]);
            $user->forceFill(['email_verified_at' => now(), 'current_team_id' => $team->id])->save();

            Membership::query()->firstOrCreate(['team_id' => $team->id, 'user_id' => $user->id], ['role' => $role]);
        }
    }

    /**
     * @param  list<Environment>  $environments
     */
    private function variable(Project $project, ?Service $service, string $key, string $value, VariableType $type, array $environments): Variable
    {
        return resolve(CreateVariable::class)->handle($project, $service, VariableInput::from([
            'key' => $key,
            'value' => $value,
            'type' => $type,
            'targets' => array_map(static fn (Environment $environment): VariableTargetData => VariableTargetData::environment($environment->public_id), $environments),
        ]), null, AuditActor::system());
    }

    private function steps(Deployment $deployment, Service $service, DeploymentStatus $status, CarbonImmutable $start): void
    {
        $plan = $service->kind === ServiceKind::Database
            ? [[DeploymentStepName::Rollout, 18], [DeploymentStepName::Health, 9], [DeploymentStepName::Route, 2]]
            : [[DeploymentStepName::Build, 64], [DeploymentStepName::Rollout, 9], [DeploymentStepName::Health, 6], [DeploymentStepName::Route, 2]];
        $cursor = $start->addSeconds(4);

        foreach ($plan as [$name, $seconds]) {
            $failed = $status === DeploymentStatus::Failed && $name === DeploymentStepName::Build;
            $running = $status === DeploymentStatus::Building && $name === DeploymentStepName::Build;

            DeploymentStep::query()->create([
                'deployment_id' => $deployment->id,
                'name' => $name,
                'status' => $failed ? DeploymentStepStatus::Failed : ($running ? DeploymentStepStatus::Running : DeploymentStepStatus::Succeeded),
                'started_at' => $cursor,
                'finished_at' => $running ? null : $cursor->addSeconds($failed ? 41 : $seconds),
                'meta' => match (true) {
                    $failed => ['message' => 'ERR_PNPM_OUTDATED_LOCKFILE'],
                    $name === DeploymentStepName::Health => ['message' => 'GET / → 200 after 3 probes'],
                    default => [],
                },
            ]);

            if ($failed || $running) {
                return;
            }

            $cursor = $cursor->addSeconds($seconds);
        }
    }

    private function logs(Deployment $deployment, Server $server, Service $service, DeploymentStatus $status): void
    {
        if ($service->kind === ServiceKind::Database) {
            $this->chunk($deployment, $server, null, LogSourceKind::Deployment, $deployment->public_id, 1, [
                [null, 'Stopping the previous postgres (stateful: brief downtime)'],
                [null, 'Starting postgres 18 with volume /var/lib/postgresql'],
                ['health', 'TCP 5432 accepted the connection'],
            ]);

            return;
        }

        $task = DaemonTask::query()->forceCreate([
            'public_id' => PublicId::generate('tsk'),
            'team_id' => $deployment->team_id,
            'server_id' => $server->id,
            'type' => 'build',
            'spec' => '{}',
            'status' => $status === DeploymentStatus::Building ? 'leased' : ($status === DeploymentStatus::Failed ? 'failed' : 'succeeded'),
            'deployment_id' => $deployment->id,
        ]);

        $lines = [
            ['clone', "\e[2m#1\e[0m Cloning acme/storefront at 3b7d5e1"],
            ['clone', 'Fetched 1 commit (depth 1), 2,481 files'],
            ['plan', "\e[1mNext.js 16.1\e[0m (App Router) · pnpm 9.12 · Node 22.11 (from .nvmrc)"],
            ['plan', 'Install pnpm install --frozen-lockfile · Build pnpm build · Start node .next/standalone/server.js'],
            ['install', '$ pnpm install --frozen-lockfile'],
        ];

        if ($status === DeploymentStatus::Failed) {
            $lines[] = ['install', 'Lockfile is up to date, resolution step is skipped'];
            $lines[] = ['install', "\e[31m ERR_PNPM_OUTDATED_LOCKFILE \e[39m Cannot install with \"frozen-lockfile\" because pnpm-lock.yaml is not up to date with package.json"];
            $lines[] = ['install', '  Failure reason:'];
            $lines[] = ['install', '  specifiers in the lockfile ({"next":"16.0.3"}) don\'t match specs in package.json ({"next":"16.1.0"})'];
            $lines[] = ['install', "\e[2mprocess \"/bin/sh -c pnpm install --frozen-lockfile\" did not complete successfully: exit code: 1\e[0m"];
            $this->chunk($deployment, $server, $task, LogSourceKind::Task, $task->public_id, 1, $lines);

            return;
        }

        $packages = ['react', 'react-dom', 'next', '@acme/ui', 'zod', 'stripe', '@sentry/nextjs', 'date-fns', 'clsx', 'tailwind-merge'];

        for ($i = 0; $i < 120; $i++) {
            $package = $packages[$i % count($packages)];
            $lines[] = ['install', sprintf("\e[2mProgress:\e[0m resolved %d, reused %d, downloaded %d, added %d · %s", 400 + $i * 9, 380 + $i * 8, $i, 400 + $i * 9, $package)];
        }

        $lines[] = ['install', "\e[32mdependencies:\e[39m"];
        $lines[] = ['install', "\e[32m+\e[39m next \e[2m16.1.0\e[0m"];
        $lines[] = ['install', "\e[32m+\e[39m react \e[2m19.2.0\e[0m"];
        $lines[] = ['install', "\e[33mWARN\e[39m 2 deprecated subdependencies found: glob@7.2.3, inflight@1.0.6"];
        $lines[] = ['build', '$ pnpm build'];
        $lines[] = ['build', "   \e[1m▲ Next.js 16.1.0\e[0m"];
        $lines[] = ['build', '   - Environments: .env.production'];
        $lines[] = ['build', ''];
        $lines[] = ['build', '   Creating an optimized production build ...'];

        if ($status !== DeploymentStatus::Building) {
            $lines[] = ['build', " \e[32m✓\e[39m Compiled successfully in 41s"];
            $lines[] = ['build', " \e[32m✓\e[39m Linting and checking validity of types"];
            $lines[] = ['build', " \e[32m✓\e[39m Generating static pages (38/38)"];

            foreach (['/', '/cart', '/checkout', '/orders', '/orders/[id]', '/products/[slug]', '/api/health'] as $route) {
                $lines[] = ['build', sprintf('├ ○ %-24s %6s kB %8s kB', $route, number_format(1.2 + strlen($route) / 10, 1), number_format(102 + strlen($route), 0))];
            }

            $lines[] = ['push', 'Pushing apps/'.$service->imageRepository().':'.strtolower($deployment->public_id)];
            $lines[] = ['push', "\e[32mpushed\e[39m sha256:4b5e57f6eb2f… (148.2 MB, 12 layers, 9 reused)"];
        }

        $this->chunk($deployment, $server, $task, LogSourceKind::Task, $task->public_id, 1, $lines);

        if ($status === DeploymentStatus::Active || $status === DeploymentStatus::Superseded) {
            $this->chunk($deployment, $server, null, LogSourceKind::Deployment, $deployment->public_id, 1, [
                ['rollout', 'Starting 1 replica next to the current version'],
                ['health', 'GET / → 200 (3 probes, 1.8s)'],
                ['route', 'Routing web-production-acme-store… to the new replica; draining the old one for 10s'],
            ]);
        }
    }

    /**
     * @param  list<array{0: string|null, 1: string}>  $lines
     */
    private function chunk(Deployment $deployment, Server $server, ?DaemonTask $task, LogSourceKind $kind, string $sourceId, int $seq, array $lines): void
    {
        $time = $deployment->started_at ?? $deployment->queued_at;

        foreach (array_chunk($lines, 50) as $index => $part) {
            LogChunk::query()->create([
                'team_id' => $deployment->team_id,
                'server_id' => $server->id,
                'daemon_task_id' => $task?->id,
                'deployment_id' => $task instanceof DaemonTask ? null : $deployment->id,
                'source_kind' => $kind,
                'source_id' => $sourceId,
                'seq' => $seq + $index,
                'lines' => array_map(static function (array $line) use (&$time): array {
                    $time = $time->addMilliseconds(170);

                    return [
                        'time' => $time->utc()->format('Y-m-d\TH:i:s.v\Z'),
                        'stream' => str_contains($line[1], 'ERR_') ? 'stderr' : 'stdout',
                        'text' => $line[1],
                        ...($line[0] === null ? [] : ['step' => $line[0]]),
                    ];
                }, $part),
                'created_at' => now(),
            ]);
        }
    }
}
