<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Backups\BackupScheduleInput;
use App\Enums\AuditAction;
use App\Exceptions\DomainRuleViolation;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupSchedule;
use Cron\CronExpression;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Edits a schedule (docs/14 §3: "editable per service; an hourly option and
 * cron expressions are available"). A database schedule may only point at a
 * destination of its own team; the control plane only at the instance one.
 */
final readonly class UpdateBackupSchedule
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(BackupSchedule $schedule, BackupScheduleInput $input, AuditActor $actor): BackupSchedule
    {
        $cron = trim((string) preg_replace('/\s+/', ' ', $input->cron));

        if (count(explode(' ', $cron)) !== 5 || ! CronExpression::isValidExpression($cron)) {
            throw DomainRuleViolation::on('cron', 'Use a 5-field cron expression, for example "15 3 * * *" (daily at 03:15 UTC).');
        }

        if (! $input->retention->keepsSomething()) {
            throw DomainRuleViolation::on('retention', 'Keep at least one snapshot.');
        }

        $destinationId = $schedule->destination_id;

        if ($input->destination !== null) {
            $destination = BackupDestination::query()
                ->where('public_id', $input->destination)
                ->where(static fn (Builder $query) => $schedule->team_id === null ? $query->whereNull('team_id') : $query->where('team_id', $schedule->team_id))
                ->first();

            if (! $destination instanceof BackupDestination) {
                throw DomainRuleViolation::on('destination', 'Pick one of this team’s destinations.');
            }

            $destinationId = $destination->id;
        }

        $schedule->forceFill([
            'cron' => $cron,
            'retention' => $input->retention,
            'enabled' => $input->enabled,
            'destination_id' => $destinationId,
        ])->save();

        $this->audit->handle(new AuditEntryData(
            action: AuditAction::BackupScheduleUpdated,
            actor: $actor,
            teamId: $schedule->team_id,
            subjectType: 'backup_schedule',
            subjectId: $schedule->public_id,
            meta: ['cron' => $cron, 'enabled' => $input->enabled, 'retention' => $input->retention->summary()],
        ));

        return $schedule;
    }
}
