<?php

declare(strict_types=1);

namespace App\Actions\Blueprints;

use App\Data\Audit\AuditActor;
use App\Data\Templates\UseTemplateData;
use App\Exceptions\DomainRuleViolation;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\Blueprints\EngineVersions;
use App\Support\Templates\TemplateCompiler;
use App\Support\Templates\TemplateDefinition;
use App\Support\Templates\TemplateInvalid;

/**
 * "Start from a template" (docs/17 §3–4): the template compiled with the
 * guided inputs (secrets are generator expressions, evaluated once when the
 * variables are created) and applied to a new project's production.
 */
final readonly class CreateProjectFromTemplate
{
    public function __construct(
        private EngineVersions $engines,
        private CreateProjectFromBlueprint $create,
    ) {}

    /**
     * @throws DomainRuleViolation
     */
    public function handle(Team $team, TemplateDefinition $template, UseTemplateData $data, User $user, AuditActor $actor): Project
    {
        $name = trim($data->name ?? $template->name);

        if ($name === '') {
            throw DomainRuleViolation::on('name', 'Name the project.');
        }

        if ($template->needsRepository() && $data->repository === null) {
            throw DomainRuleViolation::on('repository', 'Pick the repository to build from.');
        }

        try {
            $translation = TemplateCompiler::compile($template, $data->inputs, $this->engines->all(), $data->repository !== null, $name);
        } catch (TemplateInvalid $e) {
            throw DomainRuleViolation::on($e->field ?? 'template', $e->problems[0] ?? $e->getMessage());
        }

        return $this->create->handle(
            $team,
            $name,
            $translation->blueprint,
            $template->needsRepository() ? $data->repository : null,
            $template->needsRepository() ? $data->branch : null,
            ['source' => 'template', 'template' => $template->id],
            $user,
            $actor,
        );
    }
}
