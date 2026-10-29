<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Contracts\Git\SourceRepository;
use App\Data\Git\GitBranchData;
use App\Data\Git\PublicRepositoryData;
use App\Exceptions\DomainRuleViolation;
use App\Models\Git\GitHubApp;
use App\Models\Git\GitHubRepository;
use App\Models\Git\GitRepository;
use App\Models\Team;
use App\Services\Git\ConnectedRepositoryLookup;
use App\Services\Git\Exceptions\GitConnectionUnusable;
use App\Services\Git\GitProviders;
use App\Services\Git\PublicGitRemote;
use App\Services\Templates\TemplateCatalog;
use App\Support\Git\GitServerBranches;
use App\Support\Git\PublicRepositoryUrl;
use App\Support\Templates\TemplateDefinition;
use Illuminate\Contracts\Cache\Repository as Cache;
use InvalidArgumentException;
use Throwable;

/**
 * "Paste a Git URL" on New project (docs/19 §4.3, D70): checks the URL's
 * grammar, then resolves it through the team's git connections when one
 * reaches it (its row, branches and push delivery), else reads the
 * repository's branches over git smart HTTP (no API, no clone, nothing
 * stored). Names the template made for it. Answers are
 * cached for a minute per URL, so typing, pasting and continuing ask the
 * git server once.
 */
final readonly class CheckPublicRepository
{
    private const int MAX_BRANCHES = 100;

    public function __construct(
        private PublicGitRemote $remote,
        private TemplateCatalog $templates,
        private Cache $cache,
        private ConnectedRepositoryLookup $connected,
        private GitProviders $providers,
    ) {}

    /**
     * @throws DomainRuleViolation on a URL that isn't a public HTTPS repository address
     */
    public function handle(Team $team, string $input): PublicRepositoryData
    {
        try {
            $url = PublicRepositoryUrl::parse($input);
        } catch (InvalidArgumentException $e) {
            throw DomainRuleViolation::on('url', $e->getMessage());
        }

        $template = $this->templates->forRepository($url);
        $connected = $this->connected->find($team, $url);

        if ($connected instanceof SourceRepository) {
            return $this->fromConnection($team, $connected, $url, $template);
        }

        /** @var array{refs: array<string, string>, head: string|null, url: string}|array{problem: string, hidden: bool} $answer */
        $answer = $this->cache->remember('public-repo:'.hash('sha256', $url->cloneUrl()), 60, fn (): array => $this->read($url));

        if (isset($answer['problem'])) {
            return $this->data($url, $url, $template, null, [], $answer['hidden'] ? $this->hiddenProblem($team, $url) : $answer['problem']);
        }

        $resolved = PublicRepositoryUrl::parse($answer['url']);
        $branches = [];

        foreach (array_keys($answer['refs']) as $ref) {
            if (str_starts_with($ref, 'refs/heads/')) {
                $branches[] = substr($ref, 11);
            }
        }

        $default = $answer['head'] ?? GitServerBranches::defaultBranch($answer['refs']);

        if ($branches === []) {
            return $this->data($resolved, $url, $template, null, [], 'This repository has no branches yet. Push one, then paste the URL again.');
        }

        return $this->data($resolved, $url, $template, $default, $this->ordered($branches, $default), null);
    }

    /**
     * A repository one of the team's connections reaches: its own row,
     * branches and push delivery, never the public path.
     */
    private function fromConnection(Team $team, SourceRepository $repository, PublicRepositoryUrl $url, ?TemplateDefinition $template): PublicRepositoryData
    {
        $default = $repository->repositoryDefaultBranch();
        /** @var list<string> $branches */
        $branches = $this->cache->remember('connected-repo-branches:'.$team->id.':'.$repository->repositoryPublicId(), 60, function () use ($repository): array {
            try {
                return array_map(static fn (GitBranchData $branch): string => $branch->name, $this->providers->for($repository->repositoryRef()->provider)->branches($repository));
            } catch (Throwable) {
                return [];
            }
        });
        $default ??= $branches[0] ?? null;
        [$private, $webhooks, $via] = match (true) {
            $repository instanceof GitHubRepository => [$repository->private, $repository->installation->app->hasHealthyWebhooks(), 'your GitHub app'],
            $repository instanceof GitRepository => [$repository->private, $repository->gitConnection->hasHealthyWebhooks(), $repository->gitConnection->name],
            default => [false, false, null],
        };

        $data = $this->data($url, $url, $template, $default, $branches === [] ? ($default === null ? [] : [$default]) : $this->ordered($branches, $default), null);
        $data->repository = $repository->pickerData();
        $data->private = $private;
        $data->pushes = $webhooks ? 'webhook' : 'polling';
        $data->via = $via;

        return $data;
    }

    /**
     * Missing or private, and none of the team's connections reaches it:
     * say how to get access.
     */
    private function hiddenProblem(Team $team, PublicRepositoryUrl $url): string
    {
        $owner = explode('/', $url->path)[0];
        $start = "{$url->host} doesn’t show {$url->path} publicly.";

        return match ($url->provider) {
            'github' => GitHubApp::query()->where('team_id', $team->id)->exists()
                ? "{$start} If it’s private, install your GitHub app on {$owner} or give it access to this repository."
                : "{$start} If it’s private, connect GitHub in Settings → Git.",
            'gitlab' => "{$start} If it’s private, connect GitLab in Settings → Git.",
            default => "{$start} If it’s private, connect its provider or add it over SSH in Settings → Git.",
        };
    }

    /**
     * @param  list<string>  $branches
     * @return list<string> the default first, at most 100
     */
    private function ordered(array $branches, ?string $default): array
    {
        usort($branches, static fn (string $a, string $b): int => [$a !== $default, $a] <=> [$b !== $default, $b]);

        return array_slice($branches, 0, self::MAX_BRANCHES);
    }

    /**
     * @param  list<string>  $branches
     */
    private function data(PublicRepositoryUrl $at, PublicRepositoryUrl $pasted, ?TemplateDefinition $template, ?string $default, array $branches, ?string $problem): PublicRepositoryData
    {
        return new PublicRepositoryData(
            url: $at->cloneUrl(),
            fullName: $at->fullName(),
            name: $at->name(),
            host: $at->host,
            provider: $at->provider,
            defaultBranch: $default,
            branches: $branches,
            branch: $pasted->branch !== null && in_array($pasted->branch, $branches, true) ? $pasted->branch : null,
            template: $template?->id,
            problem: $problem,
        );
    }

    /**
     * @return array{refs: array<string, string>, head: string|null, url: string}|array{problem: string, hidden: bool}
     */
    private function read(PublicRepositoryUrl $url): array
    {
        try {
            return [...$this->remote->refs($url->cloneUrl()), 'url' => $url->cloneUrl()];
        } catch (GitConnectionUnusable $e) {
            // Some servers only answer on `…/name.git`.
            $suffixed = $url->withGitSuffix();

            if ($suffixed instanceof PublicRepositoryUrl) {
                try {
                    return [...$this->remote->refs($suffixed->cloneUrl()), 'url' => $suffixed->cloneUrl()];
                } catch (GitConnectionUnusable) {
                    // Report the first answer.
                }
            }

            return ['problem' => $e->getMessage(), 'hidden' => $e->getCode() === PublicGitRemote::NOT_PUBLIC];
        }
    }
}
