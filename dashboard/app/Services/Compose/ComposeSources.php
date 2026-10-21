<?php

declare(strict_types=1);

namespace App\Services\Compose;

use App\Contracts\Git\SourceRepository;
use App\Data\Blueprints\ComposeImportData;
use App\Exceptions\DomainRuleViolation;
use App\Models\Team;
use App\Services\Blueprints\EngineVersions;
use App\Services\Git\GitProviders;
use App\Support\Compose\ComposeRejected;
use App\Support\Compose\ComposeTranslation;
use App\Support\Compose\ComposeTranslator;
use App\Support\Compose\TranslationOptions;
use App\Support\Variables\EnvFileParser;

/**
 * Turns a ComposeImportData into a translation (docs/17 §2): reads the file
 * (pasted, or from the team's repository), parses the user's `.env`, and
 * runs the allow-list translator with the preview's choices.
 */
final readonly class ComposeSources
{
    public function __construct(
        private GitProviders $providers,
        private RepositoryComposeFiles $repositoryFiles,
        private EngineVersions $engines,
    ) {}

    /**
     * @return array{translation: ComposeTranslation, repository: SourceRepository|null, path: string|null}
     *
     * @throws DomainRuleViolation for a repository nobody can read
     * @throws ComposeRejected for a file that can't be imported at all
     */
    public function translate(Team $team, ComposeImportData $data): array
    {
        $repository = null;
        $path = null;
        $compose = (string) $data->compose;
        $files = [];

        foreach ($data->files as $file => $content) {
            $normalized = ltrim(preg_replace('#^\./#', '', (string) $file) ?? '', '/');

            if (preg_match('#^[A-Za-z0-9_.@+=,-]+(?:/[A-Za-z0-9_.@+=,-]+)*$#D', $normalized) === 1) {
                $files[$normalized] = $content;
            }
        }

        if ($data->source === 'repository') {
            $repository = $this->providers->repository($team, (string) $data->repository);

            if (! $repository instanceof SourceRepository) {
                throw DomainRuleViolation::on('repository', 'Pick a repository one of the team’s git connections can access.');
            }

            ['path' => $path, 'content' => $compose] = $this->repositoryFiles->composeFile($repository, (string) $data->branch);
        }

        /** @var array<string, 'managed'|'container'> $databases */
        $databases = [];

        foreach ($data->databases as $service => $choice) {
            if ($choice === 'managed' || $choice === 'container') {
                $databases[(string) $service] = $choice;
            }
        }

        $values = [];

        if ($data->envFile !== null && trim($data->envFile) !== '') {
            $parsed = EnvFileParser::parse($data->envFile);

            if ($parsed->errors !== []) {
                throw DomainRuleViolation::on('envFile', 'The values have a syntax error on line '.$parsed->errors[0]['line'].'.');
            }

            foreach ($parsed->entries as $entry) {
                $values[$entry->key] = $entry->value;
            }

            // Compose reads `.env` for interpolation and services may load it too.
            $files['.env'] ??= $data->envFile;
        }

        $translation = ComposeTranslator::translate($compose, $this->options($data, $values, $files, $databases, $repository instanceof SourceRepository));

        // Repository imports read the env files the services name from the branch.
        if ($repository instanceof SourceRepository && $translation->missingFiles !== []) {
            $found = $this->repositoryFiles->files($repository, (string) $data->branch, $translation->missingFiles);

            if ($found !== []) {
                $translation = ComposeTranslator::translate($compose, $this->options($data, $values, [...$found, ...$files], $databases, true));
            }
        }

        return ['translation' => $translation, 'repository' => $repository, 'path' => $path];
    }

    /**
     * @param  array<string, string>  $values
     * @param  array<string, string>  $files
     * @param  array<string, 'managed'|'container'>  $databases
     */
    private function options(ComposeImportData $data, array $values, array $files, array $databases, bool $repository): TranslationOptions
    {
        return new TranslationOptions(
            values: $values,
            files: $files,
            databases: $databases,
            convertBindMounts: $data->convertBindMounts,
            repository: $repository,
            engines: $this->engines->all(),
            name: $data->name,
        );
    }
}
