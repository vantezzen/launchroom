<?php

declare(strict_types=1);

namespace App\Models\Sessions;

use App\Enums\Sessions\DatabaseQueryStatus;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use App\Models\ServiceInstance;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One statement of the SQL console's per-user history (docs/07). The text is
 * AEAD-encrypted under the project DEK (App\Services\Sessions\QueryHistory);
 * audit entries only see its verb, length and hash (docs/09 R33).
 *
 * @property int $id
 * @property string $public_id
 * @property int $interactive_session_id
 * @property int $service_instance_id
 * @property int $user_id
 * @property string $op_id
 * @property string $statement ciphertext
 * @property string $statement_hash
 * @property string $verb
 * @property bool $allow_writes
 * @property DatabaseQueryStatus $status
 * @property bool $truncated
 * @property int|null $rows
 * @property int|null $duration_ms
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read InteractiveSession $session
 * @property-read ServiceInstance $serviceInstance
 * @property-read User $user
 */
#[Fillable(['team_id', 'interactive_session_id', 'service_instance_id', 'user_id', 'op_id', 'statement', 'statement_hash', 'verb', 'allow_writes'])]
#[Hidden(['statement'])]
final class DatabaseQuery extends Model
{
    use BelongsToTeam, HasPublicId;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'sent',
    ];

    public static function publicIdPrefix(): string
    {
        return 'qry';
    }

    /**
     * Records a statement's outcome (the only way its status changes).
     */
    public function finish(DatabaseQueryStatus $status, bool $truncated = false, ?int $rows = null, ?int $durationMs = null): void
    {
        $this->forceFill([
            'status' => $status,
            'truncated' => $truncated,
            'rows' => $rows,
            'duration_ms' => $durationMs,
            'finished_at' => now(),
        ])->save();
    }

    /**
     * @return BelongsTo<InteractiveSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(InteractiveSession::class, 'interactive_session_id');
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DatabaseQueryStatus::class,
            'allow_writes' => 'boolean',
            'truncated' => 'boolean',
            'rows' => 'integer',
            'duration_ms' => 'integer',
            'finished_at' => 'datetime',
        ];
    }
}
