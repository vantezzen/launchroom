<?php

declare(strict_types=1);

namespace App\Actions\ConfigAsCode;

use App\Data\Audit\AuditActor;
use App\Data\Git\RepositoryRef;
use App\Data\Services\GitSource;
use App\Enums\ConfigAsCode\ConfigTrust;
use App\Exceptions\DomainRuleViolation;
use App\Exceptions\GitHeadUnavailable;
use App\Models\Environment;
use App\Services\ConfigAsCode\ConfigApplyOutcome;
use App\Services\ConfigAsCode\ConfigFileReader;
use App\Services\Git\Exceptions\RepositoryFileUnavailable;
use App\Services\Git\GitProviders;

/**
 * "Check now" (docs/18 §3.2): reads launchroom.json at the head of the
 * branch the environment deploys and stages what it changes. Nothing is
 * deployed.
 */
final readonly class SyncConfigFile
{
    public function __construct(
        private ConfigFileReader $reader,
        private GitProviders $providers,
        private ApplyConfigFile $apply,
    ) {}

    public function handle(Environment $environment, AuditActor $actor): ConfigApplyOutcome
    {
        $environment->loadMissing('project');
        $project = $environment->project;
        $repository = $this->reader->repository($project);

        if (! $repository instanceof GitSource) {
            throw DomainRuleViolation::on('configFile', $this->reader->settings($project)->enabled
                ? 'Pick the repository that holds launchroom.json first.'
                : 'Reading launchroom.json is turned off for this project.');
        }

        $branch = $environment->triggers->branch;
        $branch = is_string($branch) && $branch !== '' && ! str_contains($branch, '*') && ! str_contains($branch, '?') ? $branch : ($repository->branch ?? 'main');

        try {
            $head = $this->providers->forSource($repository)->head(RepositoryRef::fromSource($project->team_id, $repository), $branch);
            $content = $this->reader->read($project, $repository, $head->sha);
        } catch (GitHeadUnavailable|RepositoryFileUnavailable $e) {
            throw DomainRuleViolation::on('configFile', "launchroom.json couldn't be read from {$repository->fullName} ({$branch}): {$e->getMessage()}");
        }

        return $this->apply->handle($environment, $content, $this->reader->settings($project)->path, $repository, $head->sha, ConfigTrust::Trusted, $actor);
    }
}
