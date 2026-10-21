<?php

declare(strict_types=1);

namespace App\Services\Compose;

use App\Contracts\Git\SourceRepository;
use App\Exceptions\DomainRuleViolation;
use App\Services\Git\GitProviders;
use App\Support\Compose\ComposeYaml;
use RuntimeException;

/**
 * Reads a repository's Compose file for "Import a Compose file" from a
 * repository (docs/17 §2.1): the first of compose.yaml, compose.yml,
 * docker-compose.yaml and docker-compose.yml at the root of the branch,
 * through the provider's read-only file API, plus the env files its
 * services name (`env_file`), when they exist on the branch.
 */
final readonly class RepositoryComposeFiles
{
    public const array NAMES = ['compose.yaml', 'compose.yml', 'docker-compose.yaml', 'docker-compose.yml'];

    public function __construct(
        private GitProviders $providers,
    ) {}

    /**
     * @return array{path: string, content: string}
     *
     * @throws DomainRuleViolation when there is none or it can't be read
     */
    public function composeFile(SourceRepository $repository, string $branch): array
    {
        $ref = $repository->repositoryRef();

        if (! $ref->provider->hasApi()) {
            throw DomainRuleViolation::on('repository', 'launchroom can’t read files from this git server. Paste the Compose file instead.');
        }

        $provider = $this->providers->for($ref->provider);

        foreach (self::NAMES as $name) {
            try {
                $content = $provider->fileAtRef($ref, $name, $branch);
            } catch (RuntimeException) {
                throw DomainRuleViolation::on('repository', 'The repository didn’t answer. Try again in a moment.');
            }

            if ($content !== null) {
                if (strlen($content) > ComposeYaml::MAX_BYTES) {
                    throw DomainRuleViolation::on('repository', "{$name} is larger than 256 KB.");
                }

                return ['path' => $name, 'content' => $content];
            }
        }

        throw DomainRuleViolation::on('repository', "There’s no compose.yaml or docker-compose.yml at the root of {$branch}.");
    }

    /**
     * Env files that exist on the branch (missing ones are left out).
     *
     * @param  list<string>  $paths  relative paths
     * @return array<string, string>
     */
    public function files(SourceRepository $repository, string $branch, array $paths): array
    {
        $ref = $repository->repositoryRef();
        $provider = $this->providers->for($ref->provider);
        $files = [];

        foreach (array_slice(array_values(array_unique($paths)), 0, 10) as $path) {
            try {
                $content = $provider->fileAtRef($ref, $path, $branch);
            } catch (RuntimeException) {
                continue;
            }

            if ($content !== null && strlen($content) <= 65536) {
                $files[$path] = $content;
            }
        }

        return $files;
    }
}
