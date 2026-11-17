<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Deployments\DeployEnvironment;
use App\Console\InstanceOwner;
use App\Data\Audit\AuditActor;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Local-dev harness helper (dev/apps-e2e): deploys a project's production
 * environment again, like "Deploy" with nothing staged. `--json` prints the
 * new deployments' ids. Refuses outside `local`.
 */
#[Signature('launchroom:dev:redeploy {--project= : Public id of the project} {--message=dev redeploy : Revision message} {--json : Print the deployment ids as JSON}')]
#[Description('Local dev only: deploy a project’s production environment again')]
final class DevRedeployCommand extends Command
{
    public function handle(DeployEnvironment $deploy): int
    {
        if (! app()->environment('local')) {
            $this->components->error('This command only runs in the local dev environment.');

            return self::FAILURE;
        }

        $team = InstanceOwner::teamOrFirst();
        $id = $this->option('project');
        $message = $this->option('message');

        if (! $team instanceof Team || ! is_string($id) || ! is_string($message)) {
            return self::INVALID;
        }

        $project = Project::query()->where('team_id', $team->id)->where('public_id', $id)->first();

        if (! $project instanceof Project) {
            $this->components->error("Unknown project {$id}.");

            return self::FAILURE;
        }

        $result = $deploy->handle($project->production()->sole(), InstanceOwner::first(), AuditActor::system(), $message);
        $ids = array_map(static fn (Deployment $deployment): string => $deployment->public_id, $result->deployments);

        if ($this->option('json') === true) {
            $this->line((string) json_encode(['revision' => $result->revision->number, 'deployments' => $ids], JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->info("Revision {$result->revision->number}: ".($ids === [] ? 'nothing to deploy.' : implode(', ', $ids)));

        return self::SUCCESS;
    }
}
