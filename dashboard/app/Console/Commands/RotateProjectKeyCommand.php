<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Crypto\RotateProjectKey;
use App\Models\Project;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;

#[Signature('launchroom:keys:rotate-project {project : Public id of the project (prj_…)} {--prune : Delete older key versions afterwards}')]
#[Description('Re-encrypt a project\'s variables, env snapshots and revisions with a new data key version (docs/09 R13)')]
final class RotateProjectKeyCommand extends Command
{
    public function handle(RotateProjectKey $rotate): int
    {
        $publicId = $this->argument('project');
        $publicId = is_string($publicId) ? $publicId : '';
        $project = Project::query()->where('public_id', $publicId)->first();

        if (! $project instanceof Project) {
            $this->components->error("No project {$publicId}.");

            return self::FAILURE;
        }

        try {
            $counts = $rotate->handle($project, (bool) $this->option('prune'));
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        Log::notice('Project data key rotated.', ['project' => $project->public_id, ...$counts]);

        $this->components->info("Project {$project->public_id} now encrypts with key version {$counts['version']}: re-encrypted {$counts['variables']} variables, {$counts['snapshots']} env snapshots and {$counts['revisions']} revisions".($counts['pruned'] > 0 ? "; deleted {$counts['pruned']} old key versions." : '.'));
        $this->components->warn('Build keys depend on the data key: the next deploy of each service rebuilds once.');

        return self::SUCCESS;
    }
}
