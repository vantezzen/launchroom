<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Daemon\ClientCertificateAuthority;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The launchroom-internal CA that issues server client certificates
 * (docs/03 §4.3). The key is envelope-encrypted and only ever opened by
 * {@see ClientCertificateAuthority}.
 *
 * @property int $id
 * @property string $certificate PEM
 * @property string $fingerprint lowercase hex SHA-256 of the DER
 * @property string $private_key envelope ciphertext of the PKCS#8 PEM key
 * @property string $wrapped_dek
 * @property string $kek_id
 * @property Carbon $not_before
 * @property Carbon $not_after
 * @property Carbon|null $retired_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['certificate', 'fingerprint', 'private_key', 'wrapped_dek', 'kek_id', 'not_before', 'not_after', 'retired_at'])]
#[Hidden(['private_key', 'wrapped_dek'])]
final class DaemonClientCa extends Model
{
    /**
     * Base64 of the certificate's DER (the wire form, docs/03 §4.3).
     */
    public function derBase64(): string
    {
        return preg_replace('/-----[A-Z ]+-----|\s+/', '', $this->certificate) ?? '';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'not_before' => 'datetime',
            'not_after' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }
}
