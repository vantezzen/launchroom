<?php

declare(strict_types=1);

namespace App\Models\Backups;

use App\Enums\Backups\DeletionRequestStatus;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A deletion waiting for its final backups (docs/14 §9.13): deleting an
 * environment, a project or a preview, or deploying a staged service
 * removal, first backs up every database (dump) and volume (snapshot) that
 * has backups configured. The deletion only runs once they succeeded, or
 * after a recorded "delete without final backup" override.
 *
 * @property int $id
 * @property string $public_id
 * @property int $team_id
 * @property string $subject_type project | environment | service_instance
 * @property int $subject_id
 * @property string $subject_public_id
 * @property string $subject_name display only
 * @property DeletionRequestStatus $status
 * @property list<int> $backup_run_ids
 * @property string|null $error
 * @property int|null $requested_by
 * @property int|null $overridden_by
 * @property Carbon|null $overridden_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $requester
 */
#[Fillable(['team_id', 'subject_type', 'subject_id', 'subject_public_id', 'subject_name', 'backup_run_ids', 'requested_by'])]
final class DeletionRequest extends Model
{
    use BelongsToTeam, HasPublicId;

    public const string PROJECT = 'project';

    public const string ENVIRONMENT = 'environment';

    public const string SERVICE_INSTANCE = 'service_instance';

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'backing_up',
        'backup_run_ids' => '[]',
    ];

    public static function publicIdPrefix(): string
    {
        return 'del';
    }

    /**
     * The open request of a subject, if any (locked when asked).
     */
    public static function openFor(string $type, int $id, bool $lock = false): ?self
    {
        $query = self::query()
            ->where('subject_type', $type)
            ->where('subject_id', $id)
            ->whereNotIn('status', [DeletionRequestStatus::Completed->value, DeletionRequestStatus::Cancelled->value]);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /**
     * The only way a request's status changes (docs/10 §2.2 rule 3).
     */
    public function transitionTo(DeletionRequestStatus $to, ?string $error = null): void
    {
        $this->status->assertCanTransitionTo($to);
        $this->status = $to;
        $this->error = $error === null ? null : mb_substr($error, 0, 2048);

        if ($to === DeletionRequestStatus::Completed) {
            $this->forceFill(['completed_at' => now()]);
        }

        $this->save();
    }

    /**
     * @return Collection<int, BackupRun>
     */
    public function backupRuns(): Collection
    {
        return BackupRun::query()->whereKey($this->backup_run_ids)->with(['serviceInstance.service', 'volume'])->orderBy('id')->get();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DeletionRequestStatus::class,
            'backup_run_ids' => 'array',
            'overridden_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
