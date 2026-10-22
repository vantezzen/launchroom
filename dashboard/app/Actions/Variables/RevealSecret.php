<?php

declare(strict_types=1);

namespace App\Actions\Variables;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\VariableType;
use App\Models\AuditEvent;
use App\Models\Variable;
use App\Services\Variables\VariableStore;
use Illuminate\Support\Facades\DB;

/**
 * Returns a variable's stored value. Secrets are audited (docs/06 §3.1:
 * reveal is its own permission, authorized at the entry point).
 */
final readonly class RevealSecret
{
    public function __construct(
        private VariableStore $store,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Variable $variable, AuditActor $actor): string
    {
        $variable->loadMissing('project');

        if ($variable->type === VariableType::Secret) {
            DB::transaction(fn (): AuditEvent => $this->audit->handle(new AuditEntryData(
                action: AuditAction::SecretRevealed,
                actor: $actor,
                teamId: $variable->team_id,
                subjectType: 'variable',
                subjectId: $variable->public_id,
                meta: ['key' => $variable->key],
            )));
        }

        return $this->store->plaintext($variable->project, $variable);
    }
}
