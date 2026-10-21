<?php

declare(strict_types=1);

namespace App\Actions\Instance;

use App\Actions\Deployments\RedeployService;
use App\Data\Audit\AuditActor;
use App\Exceptions\DomainRuleViolation;
use App\Models\Deployment;
use App\Models\User;
use App\Queries\MissingImages;

/**
 * Recovery checklist step e (docs/14 §5.2): every active deployment whose
 * image is gone from the registry is redeployed from its recorded commit.
 * The lost images are marked pruned and no longer count as reusable builds,
 * so the redeploy builds instead of pulling a missing image.
 */
final readonly class RebuildMissingImages
{
    public function __construct(
        private MissingImages $images,
        private RedeployService $redeploy,
    ) {}

    /**
     * @return array{started: int, failed: list<string>}
     */
    public function handle(?User $user, AuditActor $actor): array
    {
        $started = 0;
        $failed = [];

        foreach ($this->images->scan()['missing'] as $deployment) {
            Deployment::query()
                ->where('service_instance_id', $deployment->service_instance_id)
                ->where('image', $deployment->image)
                ->update(['build_key' => null, 'image_pruned_at' => now()]);

            try {
                $this->redeploy->handle($deployment->serviceInstance, $deployment, $user, $actor);
                $started++;
            } catch (DomainRuleViolation $e) {
                $failed[] = "{$deployment->serviceInstance->service->name}: {$e->getMessage()}";
            }
        }

        return ['started' => $started, 'failed' => $failed];
    }
}
