<?php

declare(strict_types=1);

namespace App\Services\Deployments;

use App\Enums\DeploymentTrigger;
use App\Models\Deployment;
use App\Models\User;
use App\Services\Crypto\ProjectKeyring;

/**
 * A new deployment that runs another one's image again as it was: same
 * image, revision, build summary and environment (re-encrypted under the
 * new id). No build and no release command (DeploymentTrigger::rerunsImage).
 * Restart and Start use it (D68), like a rollback does for an older one.
 */
final readonly class ImageRerun
{
    public function __construct(
        private ProjectKeyring $keyring,
        private DeploymentFactory $factory,
    ) {}

    public static function possible(?Deployment $source): bool
    {
        return $source instanceof Deployment && $source->image !== null && $source->image_pruned_at === null && $source->env_snapshot !== null;
    }

    /**
     * Inside the caller's transaction; the caller audits and advances it.
     */
    public function create(Deployment $source, DeploymentTrigger $trigger, ?User $user): Deployment
    {
        $source->loadMissing('environment.project');
        $project = $source->environment->project;
        $env = $this->keyring->decryptSnapshot($project, $source->public_id, (string) $source->env_snapshot);
        unset($env['LAUNCHROOM_DEPLOYMENT_ID'], $env['LAUNCHROOM_GIT_COMMIT_SHA']);

        $deployment = Deployment::query()->create([
            'team_id' => $source->team_id,
            'service_instance_id' => $source->service_instance_id,
            'environment_id' => $source->environment_id,
            'revision_id' => $source->revision_id,
            'trigger' => $trigger,
            'git_ref' => $source->git_ref,
            'commit_sha' => $source->commit_sha,
            'commit_message' => $source->commit_message,
            'commit_author' => $source->commit_author,
            'commit_author_avatar_url' => $source->commit_author_avatar_url,
            'build_key' => $source->build_key,
            'image' => $source->image,
            'queued_at' => now(),
            'actor_id' => $user?->id,
        ]);

        // The build summary travels with the image (port, health path, processes).
        $deployment->forceFill(['plan' => $source->plan, 'build_server_id' => $source->build_server_id])->save();
        $this->factory->storeSnapshot($project, $deployment, $env);

        return $deployment;
    }
}
