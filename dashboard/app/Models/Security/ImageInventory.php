<?php

declare(strict_types=1);

namespace App\Models\Security;

use App\Enums\Security\InventorySource;
use App\Enums\Security\InventoryStatus;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use App\Models\DaemonTask;
use App\Models\Service;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The packages and runtimes of one image in one team (docs/22 §3, §8).
 * Two services of a team running the same image share it; teams never do.
 * Components live in `inventory_components` (no model: up to 20,000 rows
 * written and read in bulk).
 *
 * @property int $id
 * @property string $public_id
 * @property int $team_id
 * @property string $image digest-pinned reference
 * @property string $platform
 * @property InventorySource $source
 * @property InventoryStatus $status
 * @property int|null $task_id
 * @property int|null $service_id
 * @property string|null $generator
 * @property bool $source_analyzed
 * @property array{id: string, versionId?: string}|null $distro
 * @property list<array{path: string, kind: string, packages: int}> $lockfiles
 * @property list<string> $notes
 * @property int $component_count
 * @property int $unqueried_count components without an OSV mapping
 * @property int $dropped
 * @property bool $truncated
 * @property string|null $error
 * @property int $retries
 * @property Carbon|null $failed_at
 * @property Carbon|null $scanned_at
 * @property Carbon|null $checked_at last advisory check of its components
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read DaemonTask|null $task
 * @property-read Service|null $service
 */
#[Fillable(['team_id', 'image', 'platform', 'source', 'service_id'])]
#[Table(name: 'image_inventories')]
final class ImageInventory extends Model
{
    use BelongsToTeam, HasPublicId;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'lockfiles' => '[]',
        'notes' => '[]',
        'component_count' => 0,
        'unqueried_count' => 0,
        'dropped' => 0,
        'truncated' => false,
        'source_analyzed' => false,
        'retries' => 0,
    ];

    public static function publicIdPrefix(): string
    {
        return 'inv';
    }

    /**
     * The only way an inventory's status changes (docs/10 §2.2 rule 3).
     */
    public function transitionTo(InventoryStatus $to): void
    {
        $this->status->assertCanTransitionTo($to);
        $this->status = $to;
        $this->save();
    }

    /**
     * @return BelongsTo<DaemonTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(DaemonTask::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => InventorySource::class,
            'status' => InventoryStatus::class,
            'source_analyzed' => 'boolean',
            'distro' => 'array',
            'lockfiles' => 'array',
            'notes' => 'array',
            'component_count' => 'integer',
            'unqueried_count' => 'integer',
            'dropped' => 'integer',
            'truncated' => 'boolean',
            'retries' => 'integer',
            'failed_at' => 'datetime',
            'scanned_at' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }
}
