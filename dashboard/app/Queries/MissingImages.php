<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Deployment;
use App\Models\ServiceInstance;
use App\Services\Registry\RegistryManifests;

/**
 * Active deployments whose image is no longer in the registry (docs/14
 * §5.2 step e: after a control-plane restore, unless Zot's storage was on
 * S3). Servers that already pulled the image keep running it; a restart on
 * another server or a rollback would fail, so they are rebuilt from their
 * recorded commit.
 */
final readonly class MissingImages
{
    public function __construct(
        private RegistryManifests $manifests,
    ) {}

    /**
     * @return array{missing: list<Deployment>, checked: int, unknown: int}
     */
    public function scan(int $limit = 200): array
    {
        $deployments = Deployment::query()
            ->whereIn('id', ServiceInstance::query()->select('active_deployment_id')->whereNotNull('active_deployment_id')->whereNull('removed_at'))
            ->whereNotNull('image')
            ->whereNotNull('commit_sha')
            ->with('serviceInstance.service')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $missing = [];
        $unknown = 0;

        foreach ($deployments as $deployment) {
            $exists = $deployment->image === null ? null : $this->manifests->exists($deployment->image);

            if ($exists === false) {
                $missing[] = $deployment;
            } elseif ($exists === null) {
                $unknown++;
            }
        }

        return ['missing' => $missing, 'checked' => $deployments->count(), 'unknown' => $unknown];
    }
}
