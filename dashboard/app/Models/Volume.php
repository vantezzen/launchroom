<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Backups\VolumeConsistency;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A named volume of a service instance, pinned to a server once placed.
 *
 * @property int $id
 * @property string $public_id
 * @property int $service_instance_id
 * @property string $mount_path
 * @property int|null $server_id
 * @property int|null $size_bytes
 * @property VolumeConsistency $backup_consistency how backups read it (docs/14 §2)
 * @property Carbon|null $removed_at staged removal: leaves the service with the next deploy, then retired (docs/14 §9.12)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ServiceInstance $serviceInstance
 */
#[Fillable(['team_id', 'service_instance_id', 'mount_path', 'server_id', 'backup_consistency'])]
final class Volume extends Model
{
    use BelongsToTeam, HasPublicId;

    /** @var array<string, mixed> */
    protected $attributes = [
        'backup_consistency' => 'live',
    ];

    public static function publicIdPrefix(): string
    {
        return 'vol';
    }

    /**
     * Host-side volume name, generated from the id (docs/09 R3).
     */
    public function dockerName(): string
    {
        return 'lr-v_'.substr(hash('sha256', $this->public_id), 0, 16);
    }

    /**
     * @return BelongsTo<ServiceInstance, $this>
     */
    public function serviceInstance(): BelongsTo
    {
        return $this->belongsTo(ServiceInstance::class);
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'backup_consistency' => VolumeConsistency::class, 'removed_at' => 'datetime'];
    }
}
