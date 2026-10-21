<?php

declare(strict_types=1);

namespace App\Actions\Audit;

use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Audit\AuditSinkUpdateInput;
use App\Enums\Audit\AuditSinkKind;
use App\Enums\AuditAction;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\Audit\DeliverAuditSinkJob;
use App\Models\AuditSink;
use App\Services\Audit\AuditSinkSecrets;
use Illuminate\Support\Facades\DB;

/**
 * Renames, pauses/resumes or re-keys an audit sink (docs/09 R34). Resuming
 * delivers the backlog right away. Returns a new webhook signing secret
 * when one was asked for (shown once). Audited without secrets.
 */
final readonly class UpdateAuditSink
{
    public function __construct(
        private AuditSinkSecrets $secrets,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(AuditSink $sink, AuditSinkUpdateInput $input, AuditActor $actor): ?string
    {
        if ($input->rotateSecret && $sink->kind !== AuditSinkKind::Webhook) {
            throw DomainRuleViolation::on('rotateSecret', 'Only webhooks have a signing secret.');
        }

        $signing = $input->rotateSecret ? AuditSinkSecrets::generateSigningSecret() : null;

        DB::transaction(function () use ($sink, $input, $actor, $signing): void {
            $changes = [];
            $name = trim((string) $input->name);

            if ($input->name !== null && $name !== '' && $name !== $sink->name) {
                $sink->name = $name;
                $changes[] = 'name';
            }

            if ($input->enabled !== null && $input->enabled !== $sink->enabled) {
                $sink->enabled = $input->enabled;
                // A resumed sink starts fresh: no waiting for an old backoff.
                $sink->next_attempt_at = null;
                $changes[] = $input->enabled ? 'resumed' : 'paused';
            }

            if ($signing !== null) {
                $values = $this->secrets->reveal($sink);
                $values['signingSecret'] = $signing;
                $this->secrets->seal($sink, $values);
                $changes[] = 'signing_secret';
            }

            if ($changes === []) {
                return;
            }

            $sink->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::AuditSinkUpdated,
                actor: $actor,
                subjectType: 'audit_sink',
                subjectId: $sink->public_id,
                meta: ['kind' => $sink->kind->value, 'changes' => $changes],
            ));
        });

        if ($input->enabled === true) {
            dispatch(new DeliverAuditSinkJob($sink->id, true));
        }

        return $signing;
    }
}
