<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IngressCredentialPurpose;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

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
final class IngressCredential extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => IngressCredentialPurpose::class,
        ];
    }
}
