<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SigningKeyStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A control-plane signing key's public half (docs/07). The private key lives
 * in a file (LAUNCHROOM_SIGNING_KEY_PATH), never in the database.
 *
 * @property int $id
 * @property string $kid
 * @property string $public_key
 * @property SigningKeyStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['kid', 'public_key'])]
final class SigningKey extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'active',
    ];

    public function transitionTo(SigningKeyStatus $to): void
    {
        $this->status->assertCanTransitionTo($to);
        $this->status = $to;
        $this->save();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SigningKeyStatus::class,
        ];
    }
}
