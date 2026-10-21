<?php

declare(strict_types=1);

namespace App\Actions\Updates;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Updates\UpdatePreferencesInput;
use App\Enums\AuditAction;
use App\Exceptions\DomainRuleViolation;
use App\Models\Backups\BackupDestination;
use App\Models\InstanceSettings;
use App\Models\User;
use App\Services\Updates\ReleaseChecker;
use Illuminate\Support\Facades\DB;

/**
 * Saves the update channel and the automatic-updates switch (docs/11 §4).
 * Turning automatic updates on needs a control-plane backup destination
 * (they never run without an off-site backup) and remembers the owner as
 * their actor. A channel change forgets the cached release check.
 */
final readonly class UpdateUpdatePreferences
{
    public function __construct(
        private RecordAuditEvent $audit,
        private ReleaseChecker $releases,
    ) {}

    public function handle(User $owner, UpdatePreferencesInput $input, AuditActor $actor): InstanceSettings
    {
        [$settings, $changed] = DB::transaction(function () use ($owner, $input): array {
            $settings = InstanceSettings::lockedForUpdate();
            $channelBefore = $this->releases->channel();

            if ($input->autoUpdate && ! $settings->auto_update && ! BackupDestination::query()->whereNull('team_id')->exists()) {
                throw DomainRuleViolation::on('autoUpdate', 'Automatic updates need a backup of launchroom first. Add one in Settings → Backups.');
            }

            $changed = [];

            if ($channelBefore !== $input->channel) {
                $settings->update_channel = $input->channel;
                $changed['channel'] = $input->channel;
            }

            if ($settings->auto_update !== $input->autoUpdate) {
                $settings->forceFill([
                    'auto_update' => $input->autoUpdate,
                    'auto_update_by' => $input->autoUpdate ? $owner->id : null,
                    'auto_update_changed_at' => now(),
                ]);
                $changed['autoUpdate'] = $input->autoUpdate;
            }

            $settings->save();

            return [$settings, $changed];
        });

        if (array_key_exists('channel', $changed)) {
            $this->releases->forget();
        }

        if ($changed !== []) {
            $this->audit->handle(new AuditEntryData(
                action: AuditAction::UpdatePreferencesChanged,
                actor: $actor,
                subjectType: 'instance',
                subjectId: 'instance',
                meta: $changed,
            ));
        }

        return $settings;
    }
}
