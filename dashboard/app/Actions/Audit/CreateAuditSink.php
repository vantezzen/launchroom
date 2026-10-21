<?php

declare(strict_types=1);

namespace App\Actions\Audit;

use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Audit\AuditSinkInput;
use App\Data\Audit\CreatedAuditSink;
use App\Enums\AuditAction;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\Audit\DeliverAuditSinkJob;
use App\Models\AuditSink;
use App\Models\InstanceSettings;
use App\Models\User;
use App\Services\Audit\AuditSinkSecrets;
use App\Services\Audit\AuditSinkSettings;
use App\Support\PublicId;
use Illuminate\Support\Facades\DB;

/**
 * Adds an audit log destination (docs/09 R34), instance owners only:
 * validated and vetted settings, credentials envelope-encrypted per sink, a
 * generated signing secret for webhooks (returned once). It starts at the
 * first entry, so the copy can be verified from the genesis hash; delivery
 * starts right away. Audited without any secret.
 */
final readonly class CreateAuditSink
{
    public function __construct(
        private AuditSinkSettings $settings,
        private AuditSinkSecrets $secrets,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(AuditSinkInput $input, User $user, AuditActor $actor): CreatedAuditSink
    {
        $settings = $this->settings->from($input);

        $sink = DB::transaction(function () use ($input, $user, $actor, $settings): AuditSink {
            // Serialise so two requests can't both pass the cap.
            InstanceSettings::lockedForUpdate();

            if (AuditSink::query()->count() >= AuditSink::MAX_SINKS) {
                throw DomainRuleViolation::conflict('This launchroom can forward to up to '.AuditSink::MAX_SINKS.' destinations.');
            }

            $name = trim((string) $input->name);
            $sink = new AuditSink([
                'kind' => $input->kind,
                'name' => $name === '' ? $input->kind->label() : $name,
                'config' => $settings['config'],
                'target_hint' => $settings['hint'],
                'allow_private_network' => $input->allowPrivateNetwork,
                'created_by' => $user->id,
            ]);
            $sink->public_id = PublicId::generate(AuditSink::publicIdPrefix());

            if ($settings['secret'] !== null) {
                $this->secrets->seal($sink, $settings['secret']);
            }

            $sink->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::AuditSinkCreated,
                actor: $actor,
                subjectType: 'audit_sink',
                subjectId: $sink->public_id,
                meta: ['kind' => $input->kind->value, 'target' => $settings['hint'], 'privateNetwork' => $input->allowPrivateNetwork],
            ));

            return $sink;
        });

        dispatch(new DeliverAuditSinkJob($sink->id, true));

        return new CreatedAuditSink($sink, $settings['secret']['signingSecret'] ?? null);
    }
}
