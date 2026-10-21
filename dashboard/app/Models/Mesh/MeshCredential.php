<?php

declare(strict_types=1);

namespace App\Models\Mesh;

use App\Enums\Mesh\MeshCredentialPurpose;
use App\Models\Server;
use App\Services\Mesh\MeshSecrets;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An envelope-encrypted mesh secret (docs/09 R13). Only ever written and
 * read through {@see MeshSecrets}; never serialized.
 *
 * @property int $id
 * @property string $scope `instance` or `server:<id>`
 * @property MeshCredentialPurpose $purpose
 * @property int|null $server_id
 * @property string $ciphertext
 * @property string $wrapped_dek
 * @property string $kek_id
 * @property string|null $external_id
 * @property CarbonInterface|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Hidden(['ciphertext', 'wrapped_dek', 'kek_id'])]
final class MeshCredential extends Model
{
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
        return [
            'purpose' => MeshCredentialPurpose::class,
            'expires_at' => 'datetime',
        ];
    }
}
