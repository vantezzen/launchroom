<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CliLoginStatus;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A CLI sign-in in progress (docs/16 §4). The device code is stored as its
 * SHA-256 only; the approved token's plaintext is kept encrypted (APP_KEY)
 * until the CLI picks it up, then wiped.
 *
 * @property int $id
 * @property string $public_id
 * @property string $device_code_hash
 * @property string $user_code
 * @property CliLoginStatus $status
 * @property string $client_name
 * @property string|null $client_hostname
 * @property string|null $client_version
 * @property string|null $ip
 * @property string|null $user_agent
 * @property int|null $user_id
 * @property int|null $team_id
 * @property int|null $personal_access_token_id
 * @property string|null $token_ciphertext
 * @property Carbon $expires_at
 * @property Carbon|null $decided_at
 * @property Carbon|null $consumed_at
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read Team|null $team
 * @property-read ApiToken|null $token
 */
#[Fillable(['device_code_hash', 'user_code', 'client_name', 'client_hostname', 'client_version', 'ip', 'user_agent', 'expires_at'])]
#[Hidden(['device_code_hash', 'token_ciphertext'])]
final class CliLoginRequest extends Model
{
    use HasPublicId;

    public static function publicIdPrefix(): string
    {
        return 'cli';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<ApiToken, $this>
     */
    public function token(): BelongsTo
    {
        return $this->belongsTo(ApiToken::class, 'personal_access_token_id');
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * The one way a request's status changes (docs/10 §2.2 rule 3), with
     * the columns that change along with it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function transitionTo(CliLoginStatus $to, array $attributes = []): void
    {
        $this->status->assertCanTransitionTo($to);
        $this->status = $to;
        $this->forceFill($attributes)->save();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CliLoginStatus::class,
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
