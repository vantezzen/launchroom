<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Connections\CreateConnection;
use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Variables\CreateVariable;
use App\Data\Audit\AuditActor;
use App\Data\Variables\VariableInput;
use App\Data\Variables\VariableTargetData;
use App\Enums\ConnectionMode;
use App\Enums\ConnectionStyle;
use App\Enums\LogSourceKind;
use App\Enums\TeamRole;
use App\Enums\VariableType;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\LogChunk;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use App\Models\Variable;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * A small deployed project for the deployments/variables/staged-changes UI
 * tests: team "shop" with postgres + web (connected), production deployed
 * and converged, and members per role.
 */
final readonly class DeploymentUi
{
    public function __construct(
        public Team $team,
        public Server $server,
        public Project $project,
        public Environment $production,
        public Service $postgres,
        public Service $web,
    ) {}

    public static function make(bool $deploy = true): self
    {
        $team = Team::factory()->create();
        [$server] = Projects::server($team);
        $project = Projects::create($team, 'shop');
        $postgres = Projects::postgres($project);
        $web = Projects::image($project, 'web');

        resolve(CreateConnection::class)->handle($web, $postgres, ConnectionMode::Private, ConnectionStyle::Url, null, AuditActor::system());

        $world = new self($team, $server, $project, $project->production()->sole(), $postgres, $web);

        if ($deploy) {
            $world->deploy();
        }

        return $world;
    }

    public function deploy(?string $message = null): void
    {
        resolve(DeployEnvironment::class)->handle($this->production, null, AuditActor::system(), $message);

        for ($i = 0; $i < 4; $i++) {
            Projects::report($this->server);
        }
    }

    public function member(TeamRole $role): User
    {
        return memberOf($this->team, $role);
    }

    public function variable(string $key, string $value, VariableType $type = VariableType::Config, ?Service $service = null, ?Environment $environment = null): Variable
    {
        return resolve(CreateVariable::class)->handle($this->project, $service ?? $this->web, VariableInput::from([
            'key' => $key,
            'value' => $value,
            'type' => $type,
            'targets' => [VariableTargetData::environment(($environment ?? $this->production)->public_id)],
        ]), null, AuditActor::system());
    }

    public function sharedVariable(string $key, string $value): Variable
    {
        return resolve(CreateVariable::class)->handle($this->project, null, VariableInput::from([
            'key' => $key,
            'value' => $value,
            'type' => VariableType::Config,
            'targets' => [VariableTargetData::environment($this->production->public_id)],
        ]), null, AuditActor::system());
    }

    public function latest(Service $service): Deployment
    {
        return Deployment::query()
            ->whereHas('serviceInstance', static fn (Builder $query) => $query->where('service_id', $service->id))
            ->latest('id')
            ->firstOrFail();
    }

    /**
     * @param  list<string>  $texts
     */
    public function logs(Deployment $deployment, array $texts, int $seq = 1, ?string $step = null): void
    {
        LogChunk::query()->create([
            'team_id' => $deployment->team_id,
            'server_id' => $this->server->id,
            'deployment_id' => $deployment->id,
            'source_kind' => LogSourceKind::Deployment,
            'source_id' => $deployment->public_id,
            'seq' => $seq,
            'lines' => array_map(static fn (string $text): array => [
                'time' => '2026-10-18T12:00:00.000Z',
                'stream' => 'stdout',
                'text' => $text,
                ...($step === null ? [] : ['step' => $step]),
            ], $texts),
            'created_at' => now(),
        ]);
    }

    /**
     * @return array{project: string, environment: string}
     */
    public function envArgs(): array
    {
        return ['project' => $this->project->public_id, 'environment' => $this->production->public_id];
    }

    /**
     * @return array{project: string, environment: string, service: string}
     */
    public function serviceArgs(?Service $service = null): array
    {
        return [...$this->envArgs(), 'service' => ($service ?? $this->web)->public_id];
    }

    /**
     * @return array{project: string, environment: string, deployment: string}
     */
    public function deploymentArgs(Deployment $deployment): array
    {
        return [...$this->envArgs(), 'deployment' => $deployment->public_id];
    }
}
