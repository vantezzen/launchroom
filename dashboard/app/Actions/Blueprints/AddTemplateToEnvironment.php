<?php

declare(strict_types=1);

namespace App\Actions\Blueprints;

use App\Data\Audit\AuditActor;
use App\Data\Templates\UseTemplateData;
use App\Exceptions\DomainRuleViolation;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\User;
use App\Services\Blueprints\EngineVersions;
use App\Support\Templates\TemplateCompiler;
use App\Support\Templates\TemplateDefinition;
use App\Support\Templates\TemplateInvalid;

/**
 * "Add from catalog" on a canvas (docs/17 §4): the template's services
 * added to this environment, staged like any other addition (docs/06 §4).
 */
final readonly class AddTemplateToEnvironment
{
    public function __construct(
        private EngineVersions $engines,
        private ApplyBlueprint $apply,
    ) {}

    /**
     * @return array<string, Service>
     *
     * @throws DomainRuleViolation
     */
    public function handle(Project $project, Environment $environment, TemplateDefinition $template, UseTemplateData $data, User $user, AuditActor $actor): array
    {
        if ($environment->project_id !== $project->id) {
            throw DomainRuleViolation::forbidden('That environment belongs to another project.');
        }

        if ($template->needsRepository() && $data->repository === null) {
            throw DomainRuleViolation::on('repository', 'Pick the repository to build from.');
        }

        try {
            $translation = TemplateCompiler::compile($template, $data->inputs, $this->engines->all(), $data->repository !== null, $project->name);
        } catch (TemplateInvalid $e) {
            throw DomainRuleViolation::on($e->field ?? 'template', $e->problems[0] ?? $e->getMessage());
        }

        return $this->apply->handle(
            $project,
            $environment,
            $translation->blueprint,
            $template->needsRepository() ? $data->repository : null,
            $template->needsRepository() ? $data->branch : null,
            $user,
            $actor,
        );
    }
}
