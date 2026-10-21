<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\Specs\EnvironmentSpec;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An immutable snapshot of an environment's intent, written on every Deploy
 * (docs/06 §4). `spec` holds variable values only as ciphertexts.
 *
 * @property int $id
 * @property string $public_id
 * @property int $environment_id
 * @property int $number
 * @property EnvironmentSpec $spec
 * @property string|null $message
 * @property int|null $created_by
 * @property Carbon $created_at
 * @property-read Environment $environment
 * @property-read User|null $creator
 */
#[Fillable(['team_id', 'environment_id', 'number', 'spec', 'message', 'created_by', 'created_at'])]
#[Hidden(['spec'])]
final class EnvironmentRevision extends Model
{
    use BelongsToTeam, HasPublicId;

    public const ?string UPDATED_AT = null;

    public static function publicIdPrefix(): string
    {
        return 'rev';
    }

    /**
     * Revisions are immutable (docs/07).
     */
    public static function booted(): void
    {
        self::updating(static function (): never {
            throw new \LogicException('Environment revisions are immutable.');
        });
    }

    /**
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
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
            'number' => 'integer',
            'spec' => EnvironmentSpec::class,
            'created_at' => 'datetime',
        ];
    }
}
