<?php

declare(strict_types=1);

namespace App\Actions\Blueprints;

use App\Data\Audit\AuditActor;
use App\Data\Blueprints\ComposeImportData;
use App\Exceptions\DomainRuleViolation;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\Compose\ComposeSources;
use App\Support\Compose\ComposeRejected;

/**
 * "Import a Compose file" (docs/17 §2.1): parses and translates the file
 * again (the preview is never trusted) and creates the project from it.
 */
final readonly class ImportCompose
{
    public function __construct(
        private ComposeSources $sources,
        private CreateProjectFromBlueprint $create,
    ) {}

    /**
     * @throws DomainRuleViolation
     */
    public function handle(Team $team, ComposeImportData $data, User $user, AuditActor $actor): Project
    {
        if ($data->name === null || trim($data->name) === '') {
            throw DomainRuleViolation::on('name', 'Name the project.');
        }

        try {
            ['translation' => $translation, 'repository' => $repository, 'path' => $path] = $this->sources->translate($team, $data);
        } catch (ComposeRejected $e) {
            throw DomainRuleViolation::on('compose', $e->getMessage());
        }

        return $this->create->handle(
            $team,
            trim($data->name),
            $translation->blueprint,
            $repository?->repositoryPublicId(),
            $repository !== null ? $data->branch : null,
            ['source' => 'compose', ...($path !== null ? ['file' => $path] : [])],
            $user,
            $actor,
        );
    }
}
