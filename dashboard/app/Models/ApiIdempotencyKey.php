<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A stored API response for an `Idempotency-Key` (docs/16 §2.1), per token,
 * kept 24 h. `status` is null while the first request still runs.
 *
 * @property int $id
 * @property int $personal_access_token_id
 * @property string $key
 * @property string $request_hash
 * @property int|null $status
 * @property string|null $response
 * @property Carbon $created_at
 */
#[Fillable(['personal_access_token_id', 'key', 'request_hash', 'status', 'response', 'created_at'])]
final class ApiIdempotencyKey extends Model
{
    public const null UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
