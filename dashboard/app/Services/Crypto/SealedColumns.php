<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use App\Contracts\Crypto\SealedRow;
use App\Exceptions\DecryptionFailed;
use App\Models\Alerts\AlertChannel;
use App\Models\AuditSink;
use App\Models\Backups\BackupDestination;
use App\Models\DaemonClientCa;
use App\Models\Dockerfiles\AiProviderSettings;
use App\Models\Git\GitConnection;
use App\Models\Git\GitHubApp;
use App\Models\IngressCredential;
use App\Models\Mesh\MeshCredential;
use App\Models\ProjectKey;
use App\Models\Registry\RegistryStorage;
use App\Models\Transfers\DataTransfer;
use Illuminate\Database\Eloquent\Model;
use JsonException;
use SensitiveParameter;

/**
 * Seals, reveals and re-wraps the secret columns of {@see SealedRow} models
 * (docs/09 R13): one data key per row, wrapped by the KEK; each column is
 * XChaCha20-Poly1305 under that key with the row's associated data.
 */
final readonly class SealedColumns
{
    /**
     * Every model with a `wrapped_dek` column, in rotation order.
     *
     * @var list<class-string<Model&SealedRow>>
     */
    public const array MODELS = [
        ProjectKey::class,
        GitHubApp::class,
        GitConnection::class,
        BackupDestination::class,
        IngressCredential::class,
        MeshCredential::class,
        RegistryStorage::class,
        AlertChannel::class,
        AuditSink::class,
        DaemonClientCa::class,
        AiProviderSettings::class,
        DataTransfer::class,
    ];

    public function __construct(
        private Envelope $envelope,
    ) {}

    /**
     * Gives the row a fresh data key (wrapped, with the KEK id) and returns
     * it, for sealing the row's columns. Earlier ciphertexts of the row stop
     * decrypting: seal every column again.
     */
    public function newKey(Model&SealedRow $row): SecretKey
    {
        $dek = $this->envelope->generateDataKey();
        $row->setAttribute('wrapped_dek', $this->envelope->wrap($dek, $row->sealWrapContext()));
        $row->setAttribute('kek_id', $this->envelope->kekId());

        return $dek;
    }

    /**
     * @throws DecryptionFailed
     */
    public function dataKey(Model&SealedRow $row): SecretKey
    {
        $wrapped = $row->getAttribute('wrapped_dek');

        if (! is_string($wrapped) || $wrapped === '') {
            throw DecryptionFailed::malformed();
        }

        return $this->envelope->unwrap($wrapped, $row->sealWrapContext());
    }

    /**
     * Encrypts $value into $column (null or '' clears it) under $dek, or the
     * row's existing key. The caller saves.
     *
     * @throws DecryptionFailed
     */
    public function seal(Model&SealedRow $row, string $column, #[SensitiveParameter] ?string $value, ?SecretKey $dek = null): void
    {
        $associatedData = $row->sealAssociatedData($column);

        $row->setAttribute($column, $value === null || $value === ''
            ? null
            : ($dek ?? $this->dataKey($row))->encrypt($value, $associatedData));
    }

    /**
     * @throws DecryptionFailed
     */
    public function reveal(Model&SealedRow $row, string $column): ?string
    {
        $associatedData = $row->sealAssociatedData($column);
        $ciphertext = $row->getAttribute($column);

        if (! is_string($ciphertext) || $ciphertext === '') {
            return null;
        }

        return $this->dataKey($row)->decrypt($ciphertext, $associatedData);
    }

    /**
     * @param  array<string, string>  $values
     *
     * @throws DecryptionFailed
     */
    public function sealJson(Model&SealedRow $row, string $column, #[SensitiveParameter] array $values, ?SecretKey $dek = null): void
    {
        $this->seal($row, $column, json_encode((object) $values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $dek);
    }

    /**
     * @return array<string, string>
     *
     * @throws DecryptionFailed when the column is empty or not an object
     */
    public function revealJson(Model&SealedRow $row, string $column): array
    {
        $json = $this->reveal($row, $column) ?? throw DecryptionFailed::malformed();

        try {
            $decoded = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw DecryptionFailed::malformed();
        }

        if (! is_array($decoded)) {
            throw DecryptionFailed::malformed();
        }

        $values = [];

        foreach ($decoded as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    /**
     * Re-wraps the row's data key from one KEK to another (rotate-kek); the
     * caller saves. Rows without a key are left alone.
     *
     * @throws DecryptionFailed
     */
    public static function rewrap(Model&SealedRow $row, Envelope $from, Envelope $to): void
    {
        $wrapped = $row->getAttribute('wrapped_dek');

        if (! is_string($wrapped) || $wrapped === '') {
            return;
        }

        $row->setAttribute('wrapped_dek', $from->rewrap($wrapped, $row->sealWrapContext(), $to));
        $row->setAttribute('kek_id', $to->kekId());
    }
}
