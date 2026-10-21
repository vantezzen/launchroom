<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Previews\SyncPullRequest;
use App\Data\Previews\PullRequestData;
use App\Data\Services\GitSource;
use App\Enums\Previews\PreviewProvider;
use App\Exceptions\GitHeadUnavailable;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Services\Git\GitHeads;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Local dev stand-in for GitHub pull request webhooks (GitHub isn't
 * connected in dev): drives the preview lifecycle of a project whose
 * services use plain git sources (the `git-fixtures` server, which serves
 * fixture branches such as node-hello's `preview`). The head defaults to the
 * branch head; `--sha` picks an older commit (e.g. to simulate a push later).
 * Refuses outside `local`.
 */
#[Signature('launchroom:dev:pr
    {action : open, sync, reopen, close, merge or label}
    {--project= : Project id (prj_…)}
    {--number=1 : Pull request number}
    {--branch=preview : Head branch}
    {--base=main : Target branch}
    {--sha= : Head commit (default: the branch head)}
    {--title= : Pull request title}
    {--files= : Comma-separated changed files (default: unknown, everything builds)}
    {--fork : The PR comes from a fork}
    {--author=dev : Author login}
    {--permission= : The author\'s permission (admin, write, read, none)}
    {--label=* : Labels on the PR}
    {--applied-label= : For "label": the label just applied}
    {--sender-permission=write : For "label": the permission of whoever applied it}')]
#[Description('Local dev only: simulate a pull request event for preview environments')]
final class DevPullRequestCommand extends Command
{
    private const array ACTIONS = ['open' => 'opened', 'sync' => 'synchronize', 'reopen' => 'reopened', 'close' => 'closed', 'merge' => 'closed', 'label' => 'labeled'];

    public function handle(GitHeads $heads, SyncPullRequest $sync): int
    {
        if (! app()->environment('local')) {
            $this->components->error('This command only runs in the local dev environment.');

            return self::FAILURE;
        }

        $action = $this->text('action', true) ?? '';
        $project = Project::query()->where('public_id', $this->text('project') ?? '')->first();

        if (! isset(self::ACTIONS[$action]) || ! $project instanceof Project) {
            $this->components->error('Usage: launchroom:dev:pr open|sync|reopen|close|merge|label --project=prj_…');

            return self::FAILURE;
        }

        $source = null;

        foreach ($project->services()->orderBy('id')->get() as $service) {
            /** @var Service $service */
            if ($service->source instanceof GitSource && ! $service->source->isGitHub()) {
                $source = $service->source;
                break;
            }
        }

        if (! $source instanceof GitSource) {
            $this->components->error('The project has no service with a plain git source.');

            return self::FAILURE;
        }

        $branch = $this->text('branch') ?? 'preview';
        $sha = $this->text('sha');

        try {
            $sha = $sha === null ? $heads->head($project->team_id, $source, $branch) : strtolower($sha);
        } catch (GitHeadUnavailable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $files = $this->text('files');
        $files = $files === null ? null : array_values(array_filter(array_map(trim(...), explode(',', $files)), static fn (string $file): bool => $file !== ''));
        $labelOption = $this->option('label');
        /** @var list<string> $labels */
        $labels = is_array($labelOption) ? array_values(array_filter($labelOption, is_string(...))) : [];
        $fork = $this->option('fork') === true;
        $applied = $this->text('applied-label');
        $number = $this->text('number');

        $preview = $sync->handle($project, new PullRequestData(
            provider: PreviewProvider::Git,
            installationId: null,
            repositoryId: $source->repositoryId,
            repositoryName: $source->fullName,
            action: self::ACTIONS[$action],
            number: max(1, (int) ($number ?? 1)),
            title: $this->text('title') ?? "Preview of {$branch}",
            headSha: $sha,
            headRef: $branch,
            baseRef: $this->text('base') ?? 'main',
            isFork: $fork,
            authorLogin: $this->text('author') ?? 'dev',
            authorPermission: $this->text('permission') ?? ($fork ? 'none' : 'write'),
            merged: $action === 'merge',
            labels: $applied === null ? $labels : array_values(array_unique([...$labels, $applied])),
            changedFiles: $files,
            label: $applied,
            senderLogin: $applied === null ? null : 'maintainer',
            senderPermission: $applied === null ? null : ($this->text('sender-permission') ?? 'write'),
        ));

        $this->components->info("Preview {$preview->public_id} of #{$preview->pr_number}: {$preview->status->label()}".($preview->status_reason === null ? '' : " ({$preview->status_reason})"));

        if ($preview->environment instanceof Environment) {
            $this->components->twoColumnDetail('Environment', $preview->environment->public_id.' ('.$preview->environment->slug.')');
        }

        $this->components->twoColumnDetail('Head', "{$branch} @ {$sha}");

        return self::SUCCESS;
    }

    private function text(string $name, bool $argument = false): ?string
    {
        $value = $argument ? $this->argument($name) : $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
