<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Deployments\DeployCommit;
use App\Data\Git\CommitData;
use App\Data\Services\GitSource;
use App\Enums\DeploymentTrigger;
use App\Exceptions\GitHeadUnavailable;
use App\Models\Deployment;
use App\Models\ServiceInstance;
use App\Services\Git\GitHeads;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Local dev stand-in for a push webhook on plain git sources (they have no
 * webhooks): reads the branch head from the remote and deploys it like a
 * push (DeployCommit: last applied configuration, collapse to latest).
 * `--sha` pushes a given commit of the tracked branch instead of its head
 * (a sequence of pushes with a fixture's branch overlays, dev/fixtures
 * README "Branches"). Refuses outside `local`.
 */
#[Signature('launchroom:dev:git-push {instance : Service instance id (sin_…)} {--sha= : Deploy this commit (40 hex) of the branch instead of its head}')]
#[Description('Local dev only: deploy the current head of a plain git source as if it had been pushed')]
final class DevGitPushCommand extends Command
{
    public function handle(GitHeads $heads, DeployCommit $deploy): int
    {
        if (! app()->environment('local')) {
            $this->components->error('This command only runs in the local dev environment.');

            return self::FAILURE;
        }

        $instance = ServiceInstance::query()->with(['service', 'environment'])->where('public_id', is_string($this->argument('instance')) ? $this->argument('instance') : '')->first();
        $source = $instance?->service->source;

        if (! $instance instanceof ServiceInstance || ! $source instanceof GitSource || $source->isGitHub()) {
            $this->components->error('Not a service instance with a plain git source.');

            return self::FAILURE;
        }

        $branch = $heads->branchFor($instance, $source);

        $sha = $this->option('sha');

        if (is_string($sha) && $sha !== '') {
            if (preg_match('/^[0-9a-f]{40}$/D', $sha) !== 1) {
                $this->components->error('--sha must be a full commit SHA (40 lowercase hex).');

                return self::INVALID;
            }
        } else {
            try {
                $sha = $heads->head($instance->team_id, $source, $branch);
            } catch (GitHeadUnavailable $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }
        }

        $deployment = $deploy->handle($instance, new CommitData($sha, 'dev push', 'launchroom dev', null, null), 'refs/heads/'.$branch, DeploymentTrigger::Push);

        if (! $deployment instanceof Deployment) {
            $this->components->warn("Nothing deployed for {$sha} (already deploying it, or the service is not live yet).");

            return self::SUCCESS;
        }

        $this->components->info("{$deployment->public_id} deploys {$branch} @ {$sha}.");

        return self::SUCCESS;
    }
}
