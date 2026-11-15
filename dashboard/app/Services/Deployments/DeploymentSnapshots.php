<?php

declare(strict_types=1);

namespace App\Services\Deployments;

use App\Models\Deployment;
use App\Models\Project;
use App\Services\Crypto\ProjectKeyring;
use App\Support\Variables\DeploymentSystemEnv;
use SensitiveParameter;

/**
 * A deployment's env snapshot, encrypted under the project DEK with the
 * deployment's id as context (docs/06 §3.5), always with the deployment's
 * own system variables ({@see DeploymentSystemEnv}).
 */
final readonly class DeploymentSnapshots
{
    public function __construct(
        private ProjectKeyring $keyring,
    ) {}

    /**
     * @param  array<string, string>  $env
     */
    public function store(Project $project, Deployment $deployment, #[SensitiveParameter] array $env): void
    {
        $env = DeploymentSystemEnv::add($env, $deployment->public_id, $deployment->commit_sha);

        $deployment->forceFill(['env_snapshot' => $this->keyring->encryptSnapshot($project, $deployment->public_id, $env)])->save();
    }

    /**
     * $source's environment without its own system variables, to run again
     * under another deployment.
     *
     * @return array<string, string>
     */
    public function carried(Project $project, Deployment $source): array
    {
        return DeploymentSystemEnv::strip($this->keyring->decryptSnapshot($project, $source->public_id, (string) $source->env_snapshot));
    }
}
