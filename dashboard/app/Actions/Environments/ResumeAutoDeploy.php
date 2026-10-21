<?php

declare(strict_types=1);

namespace App\Actions\Environments;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Models\Environment;
use Illuminate\Support\Facades\DB;

/**
 * Ends the auto-deploy pause a production rollback set (docs/04 §8): the
 * next matching push deploys again. Nothing is deployed right away.
 */
final readonly class ResumeAutoDeploy
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Environment $environment, AuditActor $actor): void
    {
        DB::transaction(function () use ($environment, $actor): void {
            $environment = Environment::query()->whereKey($environment->id)->lockForUpdate()->firstOrFail();

            if (! $environment->auto_deploy_paused) {
                return;
            }

            $environment->forceFill(['auto_deploy_paused' => false])->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::AutoDeployResumed,
                actor: $actor,
                teamId: $environment->team_id,
                subjectType: 'environment',
                subjectId: $environment->public_id,
                meta: [],
            ));
        });
    }
}
