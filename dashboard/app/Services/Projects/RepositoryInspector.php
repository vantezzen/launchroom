<?php

declare(strict_types=1);

namespace App\Services\Projects;

use App\Contracts\Git\SourceRepository;
use App\Data\Git\RepositoryRef;
use App\Data\Projects\InspectedAppData;
use App\Data\Projects\RepositoryInspectionData;
use App\Data\Projects\SuggestedDatabaseData;
use App\Enums\DatabaseEngine;
use App\Services\Git\Exceptions\RepositoryFileUnavailable;
use App\Services\Git\GitProviders;
use App\Support\Projects\DatabaseSuggestions;
use App\Support\Projects\RepositoryLayout;
use Illuminate\Contracts\Cache\Repository as Cache;
use RuntimeException;

/**
 * "We looked at acme/shop" (flows.md §3): reads the repository's file list
 * through its provider (docs/19 §2; GitHub: one recursive tree call with a
 * read-only token for that repository), finds its apps (RepositoryLayout)
 * and reads their `.env.example` for suggested databases
 * (DatabaseSuggestions). Only key names leave this class. Answers are cached
 * briefly per (repository, branch) so the flow can go back and forth.
 * Plain git servers have no file API: the inspection is an empty, complete
 * single-app result and detection happens in the plan task.
 */
final readonly class RepositoryInspector
{
    private const int CACHE_SECONDS = 300;

    private const int MAX_ENV_EXAMPLES = 8;

    public function __construct(
        private GitProviders $providers,
        private Cache $cache,
    ) {}

    /**
     * @throws RuntimeException when the provider can't list the files
     */
    public function inspect(SourceRepository $repository, string $branch): RepositoryInspectionData
    {
        $ref = $repository->repositoryRef();
        $key = 'repo-inspect:'.$ref->key().':'.hash('sha256', $branch);
        $cached = $this->cache->get($key);

        if (is_array($cached)) {
            return RepositoryInspectionData::from($cached);
        }

        $provider = $this->providers->for($ref->provider);
        $name = basename($repository->repositoryFullName());

        if (! $ref->provider->hasApi()) {
            return new RepositoryInspectionData(
                repository: $repository->repositoryPublicId(),
                branch: $branch,
                apps: [new InspectedAppData('', $name, 'app', true, null, [])],
                databases: [],
                complete: true,
            );
        }

        $tree = $provider->tree($ref, $branch);
        $apps = [];
        /** @var array<string, array{engine: DatabaseEngine, reason: string, apps: list<string>}> $databases */
        $databases = [];

        foreach (array_slice(RepositoryLayout::apps($tree->paths, $name), 0, 12) as $index => $app) {
            $variables = $app['envExample'] !== null && $index < self::MAX_ENV_EXAMPLES
                ? $this->envExample($ref, $app['envExample'], $branch)
                : [];

            if ($app['selected']) {
                foreach (DatabaseSuggestions::fromVariables($variables) as $suggestion) {
                    $engine = $suggestion['engine']->value;
                    $databases[$engine] ??= ['engine' => $suggestion['engine'], 'reason' => $suggestion['reason'], 'apps' => []];
                    $databases[$engine]['apps'][] = $app['rootDirectory'];
                }
            }

            $apps[] = new InspectedAppData($app['rootDirectory'], $app['name'], $app['runtime'], $app['selected'], $app['envExample'], array_keys($variables));
        }

        $result = new RepositoryInspectionData(
            repository: $repository->repositoryPublicId(),
            branch: $branch,
            apps: $apps,
            databases: array_values(array_map(static fn (array $database): SuggestedDatabaseData => new SuggestedDatabaseData($database['engine'], $database['reason'], $database['apps']), $databases)),
            complete: $tree->complete,
        );

        $this->cache->put($key, $result->toArray(), self::CACHE_SECONDS);

        return $result;
    }

    /**
     * @return array<string, string>
     */
    private function envExample(RepositoryRef $ref, string $path, string $branch): array
    {
        try {
            $body = $this->providers->for($ref->provider)->fileAtRef($ref, $path, $branch);
        } catch (RepositoryFileUnavailable) {
            return [];
        }

        return $body === null ? [] : DatabaseSuggestions::parseEnvExample($body);
    }
}
