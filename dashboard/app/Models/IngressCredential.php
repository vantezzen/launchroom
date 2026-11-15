<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Crypto\SealedRow;
use App\Enums\IngressCredentialPurpose;
use App\Models\Concerns\HasSealedSecret;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * One envelope-encrypted ingress secret (App\Services\Ingress\IngressSecrets).
 *
 * @property int $id
 * @property IngressCredentialPurpose $purpose
 * @property string $ciphertext
 * @property string $wrapped_dek
 * @property string $kek_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['purpose', 'ciphertext', 'wrapped_dek', 'kek_id'])]
#[Hidden(['ciphertext', 'wrapped_dek'])]
final class IngressCredential extends Model implements SealedRow
{
    use HasSealedSecret;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => IngressCredentialPurpose::class,
        ];
    }

    public function sealWrapContext(): string
    {
        return 'ingress_credential:'.$this->purpose->value;
    }

    public function sealAssociatedData(string $column): string
    {
        return match ($column) {
            'ciphertext' => 'ingress_credential|'.$this->purpose->value,
            default => throw new InvalidArgumentException("{$column} is not a sealed column."),
        };
    }
}
