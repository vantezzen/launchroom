<?php

declare(strict_types=1);

namespace App\Actions\Variables;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Exceptions\DomainRuleViolation;
use App\Models\Variable;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a variable from the intent (staged; Discard restores it from the
 * last revision with the same id, version and ciphertext).
 */
final readonly class DeleteVariable
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Variable $variable, AuditActor $actor): void
    {
        if ($variable->connection_id !== null) {
            throw DomainRuleViolation::on('variable', 'This variable belongs to a connection; remove the connection instead.');
        }

        DB::transaction(function () use ($variable, $actor): void {
            $variable->delete();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::VariableDeleted,
                actor: $actor,
                teamId: $variable->team_id,
                subjectType: 'variable',
                subjectId: $variable->public_id,
                meta: ['key' => $variable->key],
            ));
        });
    }
}
