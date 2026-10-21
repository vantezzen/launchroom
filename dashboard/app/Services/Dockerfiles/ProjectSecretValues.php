<?php

declare(strict_types=1);

namespace App\Services\Dockerfiles;

use App\Enums\VariableType;
use App\Models\Project;
use App\Models\Variable;
use App\Services\Crypto\ProjectKeyring;
use Throwable;

/**
 * The plaintext values of a project's secret variables, only to redact them
 * from what is sent to the model (docs/20 §2). Never stored, never logged.
 */
final readonly class ProjectSecretValues
{
    public function __construct(
        private ProjectKeyring $keyring,
    ) {}

    /**
     * @return list<string>
     */
    public function for(Project $project): array
    {
        $values = [];

        $variables = Variable::query()
            ->where('project_id', $project->id)
            ->where('type', VariableType::Secret->value)
            ->limit(2000)
            ->get(['id', 'public_id', 'project_id', 'version', 'value']);

        foreach ($variables as $variable) {
            try {
                $values[] = $this->keyring->decryptVariable($project, $variable->public_id, $variable->version, $variable->value);
            } catch (Throwable) {
                // An undecryptable value can't leak either.
            }
        }

        return $values;
    }
}
