<?php

declare(strict_types=1);

namespace App\Models\Security;

use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use App\Models\ServiceInstance;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A firewall exception of a service instance (docs/22 §6.4): one CRS rule
 * skipped, optionally below a path prefix and for one part of the request.
 * Typed fields only; launchroomd renders the directive.
 *
 * @property int $id
 * @property string $public_id
 * @property int $team_id
 * @property int $service_instance_id
 * @property int $rule_id
 * @property string|null $path_prefix
 * @property string|null $target
 * @property string|null $note
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ServiceInstance $serviceInstance
 * @property-read User|null $creator
 */
#[Fillable(['team_id', 'service_instance_id', 'rule_id', 'path_prefix', 'target', 'note', 'created_by'])]
#[Table(name: 'waf_exclusions')]
final class WafExclusion extends Model
{
    use BelongsToTeam, HasPublicId;

    public const int MAX_PER_INSTANCE = 50;

    public static function publicIdPrefix(): string
    {
        return 'wfx';
    }

    /**
     * @return BelongsTo<ServiceInstance, $this>
     */
    public function serviceInstance(): BelongsTo
    {
        return $this->belongsTo(ServiceInstance::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rule_id' => 'integer',
        ];
    }
}
