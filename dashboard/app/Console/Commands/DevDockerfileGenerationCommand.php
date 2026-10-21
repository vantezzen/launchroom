<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Dockerfiles\StartDockerfileGeneration;
use App\Actions\Dockerfiles\UseGeneratedDockerfile;
use App\Actions\Projects\CreateProject;
use App\Actions\Services\CreateService;
use App\Data\Audit\AuditActor;
use App\Data\Projects\CreateProjectData;
use App\Data\Services\CreateServiceData;
use App\Data\Services\GitSource;
use App\Enums\DeploymentStatus;
use App\Enums\Dockerfiles\DockerfileGenerationStatus;
use App\Enums\Dockerfiles\DockerfileGenerationTrigger;
use App\Enums\ServiceKind;
use App\Models\Deployment;
use App\Models\Dockerfiles\AiProviderSettings;
use App\Models\Domain;
use App\Models\Team;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;

/**
 * Local-dev end to end of "Generate a Dockerfile" (docs/20 §8): a project
 * with a fixture Railpack can't plan, both opt-in switches on, a generation
 * through the dind servers (inspect plan → stub model → lint/pin → build →
 * start probe → verified), then "Use this Dockerfile" and a deploy. Needs
 * LAUNCHROOM_DOCKERFILE_MODEL=stub (no real key in dev) unless a key is set.
 * Refuses outside `local`.
 */
#[Signature('launchroom:dev:dockerfile-generation {--name=generated : Project name} {--fixture=unplannable-site : Fixture in dev/fixtures} {--git-url=http://git-fixtures:8080 : Base URL of the fixture git server} {--timeout=600 : Seconds to wait for each phase} {--no-deploy : Stop after the Dockerfile is verified}')]
#[Description('Local dev only: generate, verify, use and deploy a Dockerfile for a fixture Railpack cannot plan')]
final class DevDockerfileGenerationCommand extends Command
{
    public function handle(CreateProject $createProject, CreateService $createService, StartDockerfileGeneration $start, UseGeneratedDockerfile $use, DeployEnvironment $deploy): int
    {
        if (! app()->environment('local')) {
            $this->components->error('This command only runs in the local dev environment.');

            return self::FAILURE;
        }

        $owner = User::query()->where('is_instance_owner', true)->orderBy('id')->first();
        $team = ($owner instanceof User ? $owner->currentTeam : null) ?? Team::query()->orderBy('id')->first();
        $fixture = $this->option('fixture');
        $name = $this->option('name');
        $gitUrl = $this->option('git-url');
        $timeout = (int) $this->option('timeout');

        if (! $owner instanceof User || ! $team instanceof Team || ! is_string($fixture) || ! is_string($name) || ! is_string($gitUrl) || preg_match('/^[a-z0-9-]{1,64}$/D', $fixture) !== 1) {
            $this->components->error('Finish /setup first (an instance owner and a team), and pass a fixture name.');

            return self::FAILURE;
        }

        $settings = AiProviderSettings::current();
        $settings->forceFill(['enabled' => true])->save();
        $teamSettings = clone $team->settings;
        $teamSettings->generateDockerfiles = true;
        $team->forceFill(['settings' => $teamSettings])->save();
        $this->components->info('Dockerfile generation: on for the instance (model '.config()->string('launchroom.dockerfile_generation.model').') and for '.$team->name.'.');

        $actor = AuditActor::system();
        $project = $createProject->handle($team, new CreateProjectData($name), $actor);
        $service = $createService->handle($project, CreateServiceData::from([
            'name' => 'site',
            'kind' => ServiceKind::App,
            'source' => ['type' => 'git', 'provider' => GitSource::PROVIDER_GIT, 'repositoryId' => 0, 'fullName' => 'fixtures/'.$fixture, 'cloneUrl' => rtrim($gitUrl, '/').'/'.$fixture.'.git', 'branch' => 'main'],
        ]), $owner, $actor);
        $instance = $service->instances()->sole();

        $generation = $start->handle($instance, $owner, DockerfileGenerationTrigger::PlanFailed, $actor);
        $this->components->info("Started {$generation->public_id} for {$project->name}/site.");

        $deadline = time() + $timeout;
        $last = null;

        while (time() < $deadline) {
            $generation->refresh();

            if ($generation->status !== $last) {
                $this->line('  '.now()->format('H:i:s').'  '.$generation->status->value.' (attempt '.$generation->attempt.'/'.$generation->max_attempts.')');
                $last = $generation->status;
            }

            if ($generation->status->isTerminal()) {
                break;
            }

            Sleep::sleep(2);
        }

        if ($generation->status !== DockerfileGenerationStatus::Verified) {
            $this->components->error('Not verified: '.($generation->error ?? $generation->status->value));

            return self::FAILURE;
        }

        $this->line((string) $generation->dockerfile);
        $use->handle($generation, $owner, $actor);
        $this->components->info('Using the generated Dockerfile (staged).');

        if ($this->option('no-deploy')) {
            return self::SUCCESS;
        }

        $deploy->handle($project->production()->sole(), $owner, $actor);
        $deadline = time() + $timeout;

        while (time() < $deadline) {
            $deployment = Deployment::query()->where('service_instance_id', $instance->id)->latest('id')->first();

            if ($deployment instanceof Deployment && in_array($deployment->status, [DeploymentStatus::Active, DeploymentStatus::Failed, DeploymentStatus::Cancelled], true)) {
                if ($deployment->status !== DeploymentStatus::Active) {
                    $this->components->error('Deployment '.$deployment->status->value.': '.($deployment->failure_message ?? ''));

                    return self::FAILURE;
                }

                $domain = Domain::query()->where('service_instance_id', $instance->id)->value('hostname');
                $this->components->info('Live'.(is_string($domain) ? ": https://{$domain}:8443/" : '.'));

                return self::SUCCESS;
            }

            Sleep::sleep(3);
        }

        $this->components->error('Timed out waiting for the deployment.');

        return self::FAILURE;
    }
}
