<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Data\Git\PublicRepositoryData;
use App\Exceptions\DomainRuleViolation;
use App\Services\Git\Exceptions\GitConnectionUnusable;
use App\Services\Git\PublicGitRemote;
use App\Services\Templates\TemplateCatalog;
use App\Support\Git\GitServerBranches;
use App\Support\Git\PublicRepositoryUrl;
use App\Support\Templates\TemplateDefinition;
use Illuminate\Contracts\Cache\Repository as Cache;
use InvalidArgumentException;

/**
 * "Paste a Git URL" on New project (docs/19 §4.3, D70): checks the URL's
 * grammar, reads the repository's branches over git smart HTTP (no API, no
 * clone, nothing stored) and names the template made for it. Answers are
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
    ) {}

    /**
     * @throws DomainRuleViolation on a URL that isn't a public HTTPS repository address
     */
    public function handle(string $input): PublicRepositoryData
    {
        try {
            $url = PublicRepositoryUrl::parse($input);
        } catch (InvalidArgumentException $e) {
            throw DomainRuleViolation::on('url', $e->getMessage());
        }

        $template = $this->templates->forRepository($url);

        /** @var array{refs: array<string, string>, head: string|null, url: string}|string $answer */
        $answer = $this->cache->remember('public-repo:'.hash('sha256', $url->cloneUrl()), 60, fn (): array|string => $this->read($url));

        if (is_string($answer)) {
            return $this->data($url, $url, $template, null, [], $answer);
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

        usort($branches, static fn (string $a, string $b): int => [$a !== $default, $a] <=> [$b !== $default, $b]);

        return $this->data($resolved, $url, $template, $default, array_slice($branches, 0, self::MAX_BRANCHES), null);
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
     * @return array{refs: array<string, string>, head: string|null, url: string}|string the refs, or the reason they can't be read
     */
    private function read(PublicRepositoryUrl $url): array|string
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

            return $e->getMessage();
        }
    }
}
