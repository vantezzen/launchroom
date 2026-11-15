<?php

declare(strict_types=1);

namespace App\Models\Mesh;

use App\Contracts\Crypto\SealedRow;
use App\Enums\Mesh\MeshCredentialPurpose;
use App\Models\Concerns\HasSealedSecret;
use App\Models\Server;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * An envelope-encrypted mesh secret (docs/09 R13). Only ever written and
 * read through App\Services\Mesh\MeshSecrets; never serialized.
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
final class MeshCredential extends Model implements SealedRow
{
    use HasSealedSecret;

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

    public function sealWrapContext(): string
    {
        return "mesh_credential:{$this->scope}:{$this->purpose->value}";
    }

    public function sealAssociatedData(string $column): string
    {
        return match ($column) {
            'ciphertext' => "mesh_credential|{$this->scope}|{$this->purpose->value}",
            default => throw new InvalidArgumentException("{$column} is not a sealed column."),
        };
    }
}
