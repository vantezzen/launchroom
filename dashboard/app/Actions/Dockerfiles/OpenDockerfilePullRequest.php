<?php

declare(strict_types=1);

namespace App\Actions\Dockerfiles;

use App\Actions\Audit\RecordAuditEvent;
use App\Contracts\Git\OpensPullRequests;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Git\FilePullRequest;
use App\Data\Git\RepositoryRef;
use App\Data\Services\GitSource;
use App\Enums\AuditAction;
use App\Enums\Dockerfiles\DockerfileGenerationStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Dockerfiles\DockerfileGeneration;
use App\Queries\DockerfileGenerationView;
use App\Services\Git\GitProviders;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * "Commit to repository" (docs/20 §5): a pull/merge request adding the
 * verified Dockerfile at `<root>/Dockerfile` on a new branch. Never
 * overwrites a file; the provider call happens outside any transaction.
 */
final readonly class OpenDockerfilePullRequest
{
    public function __construct(
        private GitProviders $providers,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(DockerfileGeneration $generation, AuditActor $actor): string
    {
        $generation->loadMissing(['service', 'serviceInstance']);
        $source = $generation->service->source;

        if ($generation->status !== DockerfileGenerationStatus::Verified || $generation->dockerfile === null) {
            throw DomainRuleViolation::conflict('Only a Dockerfile that built and started can be committed.');
        }

        if ($generation->pull_request_url !== null) {
            return $generation->pull_request_url;
        }

        if (! $source instanceof GitSource || $source->isUnconnectedPlainGit()) {
            throw DomainRuleViolation::on('pullRequest', 'This repository has no connection that can open pull requests. Download the Dockerfile instead.');
        }

        $provider = $this->providers->forSource($source);

        if (! $provider instanceof OpensPullRequests) {
            throw DomainRuleViolation::on('pullRequest', "{$source->kind()->label()} repositories can't get pull requests from launchroom. Download the Dockerfile instead.");
        }

        $path = DockerfileGenerationView::repositoryPath($source);
        $short = strtolower(substr($generation->public_id, -8));
        $body = implode("\n\n", array_filter([
            'launchroom drafted this Dockerfile with '.$generation->model.' and verified it: it built on a build server and the app answered on port '.($generation->port ?? '?').'.',
            $generation->explanation,
            'Merge it, then switch the service to "Dockerfile" in Settings → Build to build from the file in your repository.',
        ]));

        $url = Cache::lock("launchroom:dockerfile-pr:{$generation->id}", 60)->block(30, function () use ($provider, $source, $generation, $path, $short, $body, $actor): string {
            $generation->refresh();

            if ($generation->pull_request_url !== null) {
                return $generation->pull_request_url;
            }

            try {
                $url = $provider->openFilePullRequest(RepositoryRef::fromSource($generation->team_id, $source), new FilePullRequest(
                    path: $path,
                    content: (string) $generation->dockerfile,
                    branch: "launchroom/dockerfile-{$short}",
                    commitMessage: 'Add a Dockerfile (verified by launchroom)',
                    title: 'Add a Dockerfile',
                    body: $body,
                ));
            } catch (RuntimeException $e) {
                throw DomainRuleViolation::on('pullRequest', $e->getMessage());
            }

            $generation->forceFill(['pull_request_url' => $url])->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::DockerfileGenerationPullRequestOpened,
                actor: $actor,
                teamId: $generation->team_id,
                subjectType: 'service_instance',
                subjectId: $generation->serviceInstance->public_id,
                meta: ['generation' => $generation->public_id, 'url' => $url, 'path' => $path],
            ));

            return $url;
        });

        return is_string($url) ? $url : '';
    }
}
