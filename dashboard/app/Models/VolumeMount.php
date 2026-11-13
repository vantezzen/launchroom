<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A volume of another service mounted by this service instance (a shared
 * volume, D73). Both instances are in the same environment and run on the
 * server that holds the volume. The volume keeps its owner; this row is
 * intent, applied with the next deploy like every other setting.
 *
 * @property int $id
 * @property int $volume_id
 * @property int $service_instance_id
 * @property string $mount_path
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Volume $volume
 * @property-read ServiceInstance $serviceInstance
 */
#[Fillable(['team_id', 'volume_id', 'service_instance_id', 'mount_path'])]
final class VolumeMount extends Model
{
    use BelongsToTeam;

    /**
     * @return BelongsTo<Volume, $this>
     */
    public function volume(): BelongsTo
    {
        return $this->belongsTo(Volume::class);
    }

    /**
     * @return BelongsTo<ServiceInstance, $this>
     */
    public function serviceInstance(): BelongsTo
    {
        return $this->belongsTo(ServiceInstance::class);
    }
}
