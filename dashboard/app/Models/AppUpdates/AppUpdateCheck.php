<?php

declare(strict_types=1);

namespace App\Models\AppUpdates;

use App\Data\AppUpdates\UpdateCandidateData;
use App\Enums\AppUpdates\UpdateCheckStatus;
use App\Models\Concerns\BelongsToTeam;
use App\Models\ServiceInstance;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\LaravelData\DataCollection;

/**
 * The latest version check of one image service instance (docs/04 §14.1):
 * what runs, what is newer, when to look again. Nothing here is secret.
 *
 * @property int $id
 * @property int $team_id
 * @property int $service_instance_id
 * @property string|null $image the running reference (`docker.io/listmonk/listmonk:v6.2.0`)
 * @property string|null $running_digest
 * @property Carbon|null $running_published_at
 * @property UpdateCheckStatus $status
 * @property string|null $reason why it is unknown or failed, in one sentence
 * @property DataCollection<int, UpdateCandidateData> $candidates biggest change first
 * @property string|null $release_notes_url
 * @property Carbon|null $checked_at
 * @property Carbon|null $next_check_at
 * @property int $failures
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ServiceInstance $serviceInstance
 */
#[Fillable(['team_id', 'service_instance_id'])]
final class AppUpdateCheck extends Model
{
    use BelongsToTeam;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'unknown',
        'candidates' => '[]',
        'failures' => 0,
    ];

    /**
     * @return BelongsTo<ServiceInstance, $this>
     */
    public function serviceInstance(): BelongsTo
    {
        return $this->belongsTo(ServiceInstance::class);
    }

    /**
     * The candidate an update to $tag would use.
     */
    public function candidate(string $tag): ?UpdateCandidateData
    {
        foreach ($this->candidates as $candidate) {
            if ($candidate->tag === $tag) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => UpdateCheckStatus::class,
            'candidates' => DataCollection::class.':'.UpdateCandidateData::class.',default',
            'running_published_at' => 'datetime',
            'checked_at' => 'datetime',
            'next_check_at' => 'datetime',
            'failures' => 'integer',
        ];
    }
}
