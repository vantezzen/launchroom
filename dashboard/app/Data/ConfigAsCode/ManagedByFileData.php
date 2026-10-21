<?php

declare(strict_types=1);

namespace App\Data\ConfigAsCode;

use App\Enums\ConfigAsCode\ConfigFileStatus;
use App\Models\ConfigAsCode\EnvironmentConfigFile;
use Spatie\LaravelData\Data;

/**
 * "Managed by launchroom.json" for one service (docs/18 §3.5): which of its
 * settings come from the file, and where to edit them.
 */
final class ManagedByFileData extends Data
{
    /**
     * @param  list<string>  $fields  setting paths (`build.buildCommand`, `scaling.replicas`, `name`, …)
     * @param  list<string>  $variables
     * @param  list<string>  $domains
     */
    public function __construct(
        public string $path,
        public ?string $repository,
        public ?string $commitSha,
        public ConfigFileStatus $status,
        /** The file on GitHub at that commit, at the service's line. */
        public ?string $editUrl,
        public array $fields,
        public array $variables,
        public array $domains,
    ) {}

    public static function for(?EnvironmentConfigFile $record, string $servicePublicId): ?self
    {
        $managed = $record?->managed->service($servicePublicId);

        if (! $record instanceof EnvironmentConfigFile || $managed === null || ($managed->fields === [] && $managed->variables === [] && $managed->domains === [])) {
            return null;
        }

        return new self($record->path, $record->repository, $record->commit_sha, $record->status, $record->webUrl($managed->line), $managed->fields, $managed->variables, $managed->domains);
    }
}
