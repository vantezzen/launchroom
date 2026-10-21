<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Git\StartGitProviderConnection;
use App\Actions\Previews\UpdatePreviewSettings;
use App\Actions\Projects\ImportRepository;
use App\Contracts\Git\SourceRepository;
use App\Data\Audit\AuditActor;
use App\Data\Git\ConnectGitProviderData;
use App\Data\Previews\PreviewSettingsInput;
use App\Data\Projects\ImportRepositoryData;
use App\Enums\DeploymentTrigger;
use App\Enums\Git\GitProviderKind;
use App\Enums\Previews\ForkPolicy;
use App\Models\Deployment;
use App\Models\Git\GitConnection;
use App\Models\Previews\Preview;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\Git\GitProviders;
use App\Services\Git\Providers\GiteaProvider;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Local-dev end-to-end steps for the git providers (docs/19 §8,
 * `make test-git-e2e`): `connect` the dev Forgejo with an access token (as
 * the instance owner, private network allowed), `import` one of its
 * repositories as a project with previews on and deploy it, and `status`
 * (JSON: the project's deployments and previews) for the script to poll.
 * Refuses outside `local`.
 */
#[Signature('launchroom:dev:git-provider {step : connect, import or status} {--url=http://forgejo:3000 : Server URL} {--token= : Access token (connect)} {--repository= : owner/name (import)} {--name=forgejo-hello : Project name (import, status)}')]
#[Description('Local dev only: connect the dev Forgejo, import a repository, report deployments and previews')]
final class DevGitProviderCommand extends Command
{
    public function handle(StartGitProviderConnection $connect, ImportRepository $import, DeployEnvironment $deploy, UpdatePreviewSettings $previews, GitProviders $providers, GiteaProvider $gitea): int
    {
        if (! app()->environment('local')) {
            $this->components->error('This command only runs in the local dev environment.');

            return self::FAILURE;
        }

        $owner = User::query()->where('is_instance_owner', true)->orderBy('id')->first();
        $team = $owner instanceof User && $owner->currentTeam instanceof Team ? $owner->currentTeam : Team::query()->orderBy('id')->first();

        if (! $owner instanceof User || ! $team instanceof Team) {
            $this->components->error('No instance owner. Finish /setup first.');

            return self::FAILURE;
        }

        $url = is_string($this->option('url')) ? $this->option('url') : '';
        $name = is_string($this->option('name')) ? $this->option('name') : '';

        return match ($this->argument('step')) {
            'connect' => $this->connect($connect, $team, $owner, $url),
            'import' => $this->import($import, $deploy, $previews, $providers, $gitea, $team, $owner, $url, $name),
            'status' => $this->status($team, $name),
            default => self::INVALID,
        };
    }

    private function connect(StartGitProviderConnection $connect, Team $team, User $owner, string $url): int
    {
        $existing = GitConnection::query()->where('team_id', $team->id)->where('provider', GitProviderKind::Gitea->value)->where('base_url', rtrim($url, '/'))->first();

        if ($existing instanceof GitConnection) {
            $this->line($existing->public_id);

            return self::SUCCESS;
        }

        $connect->handle($team, $owner, new ConnectGitProviderData('gitea', $url, 'token', token: is_string($this->option('token')) ? $this->option('token') : null, allowPrivateNetwork: true), AuditActor::system());
        $id = GitConnection::query()->where('team_id', $team->id)->latest('id')->value('public_id');
        $this->line(is_string($id) ? $id : '');

        return self::SUCCESS;
    }

    private function import(ImportRepository $import, DeployEnvironment $deploy, UpdatePreviewSettings $previews, GitProviders $providers, GiteaProvider $gitea, Team $team, User $owner, string $url, string $name): int
    {
        $connection = GitConnection::query()->where('team_id', $team->id)->where('base_url', rtrim($url, '/'))->first();
        $fullName = is_string($this->option('repository')) ? $this->option('repository') : '';

        if (! $connection instanceof GitConnection) {
            $this->components->error('Connect first.');

            return self::FAILURE;
        }

        $repository = null;

        foreach ($gitea->searchConnection($connection, basename($fullName)) as $candidate) {
            if ($candidate->full_name === $fullName) {
                $repository = $candidate;
            }
        }

        if ($repository === null || ! $providers->repository($team, $repository->public_id) instanceof SourceRepository) {
            $this->components->error("{$fullName} is not visible through the connection.");

            return self::FAILURE;
        }

        if (Project::query()->where('team_id', $team->id)->where('name', $name)->exists()) {
            $this->components->error("A project named {$name} exists; delete it first.");

            return self::FAILURE;
        }

        $project = $import->handle($team, ImportRepositoryData::from([
            'name' => $name,
            'repository' => $repository->public_id,
            'branch' => $repository->default_branch ?? 'main',
            'apps' => [['rootDirectory' => '', 'name' => 'web', 'port' => 3000, 'processes' => []]],
            'databases' => [],
        ]), $owner, AuditActor::system());

        $previews->handle($project, new PreviewSettingsInput(true, null, null, null, 2, 7, ForkPolicy::Off, false, false), $owner, AuditActor::system());
        $deploy->handle($project->production()->sole(), $owner, AuditActor::system(), 'Imported from '.$fullName, DeploymentTrigger::Config);
        $this->line($project->public_id);

        return self::SUCCESS;
    }

    private function status(Team $team, string $name): int
    {
        $project = Project::query()->where('team_id', $team->id)->where('name', $name)->first();

        if (! $project instanceof Project) {
            $this->line('{}');

            return self::SUCCESS;
        }

        $environments = $project->environments()->pluck('id');
        $deployments = Deployment::query()->whereIn('environment_id', $environments)->orderBy('id')->get()
            ->map(static fn (Deployment $deployment): array => ['environment' => $deployment->environment_id, 'sha' => $deployment->commit_sha, 'status' => $deployment->status->value, 'trigger' => $deployment->trigger->value])
            ->all();
        $previews = Preview::query()->where('project_id', $project->id)->orderBy('id')->get()
            ->map(static fn (Preview $preview): array => ['number' => $preview->pr_number, 'status' => $preview->status->value, 'sha' => $preview->head_sha, 'provider' => $preview->provider->value])
            ->all();

        $this->line(json_encode(['project' => $project->public_id, 'deployments' => $deployments, 'previews' => $previews], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
