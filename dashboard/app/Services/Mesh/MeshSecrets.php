<?php

declare(strict_types=1);

namespace App\Services\Mesh;

use App\Enums\Mesh\MeshCredentialPurpose;
use App\Models\Mesh\MeshCredential;
use App\Models\Server;
use App\Services\Crypto\Envelope;
use App\Services\Crypto\Exceptions\DecryptionFailed;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use SensitiveParameter;

/**
 * Envelope encryption of mesh secrets (docs/09 R13): the Headscale API key,
 * the "bring your own Tailscale" auth key and per-server pre-auth keys.
 *
 * Each row gets its own data key, wrapped by the KEK with the row's scope and
 * purpose as context; the value is bound to `mesh_credential|scope|purpose`
 * as associated data, so ciphertexts can't be swapped between rows.
 */
final readonly class MeshSecrets
{
    public function __construct(
        private Envelope $envelope,
    ) {}

    public static function instanceScope(): string
    {
        return 'instance';
    }

    public static function serverScope(Server $server): string
    {
        return 'server:'.$server->id;
    }

    public function put(
        string $scope,
        MeshCredentialPurpose $purpose,
        #[SensitiveParameter] string $value,
        ?string $externalId = null,
        ?CarbonInterface $expiresAt = null,
        ?Server $server = null,
    ): MeshCredential {
        $dek = $this->envelope->generateDataKey();
        $credential = $this->find($scope, $purpose) ?? new MeshCredential;
        $credential->scope = $scope;
        $credential->purpose = $purpose;
        $credential->server_id = $server?->id;
        $credential->wrapped_dek = $this->envelope->wrap($dek, $this->wrapContext($scope, $purpose));
        $credential->kek_id = $this->envelope->kekId();
        $credential->ciphertext = $dek->encrypt($value, $this->associatedData($scope, $purpose));
        $credential->external_id = $externalId;
        $credential->expires_at = $expiresAt instanceof CarbonInterface ? Date::instance($expiresAt) : null;
        $credential->save();

        return $credential;
    }

    public function find(string $scope, MeshCredentialPurpose $purpose): ?MeshCredential
    {
        return MeshCredential::query()->where('scope', $scope)->where('purpose', $purpose->value)->first();
    }

    /**
     * @throws DecryptionFailed
     */
    public function reveal(MeshCredential $credential): string
    {
        $dek = $this->envelope->unwrap($credential->wrapped_dek, $this->wrapContext($credential->scope, $credential->purpose));

        return $dek->decrypt($credential->ciphertext, $this->associatedData($credential->scope, $credential->purpose));
    }

    /**
     * The decrypted value, or null when there is none.
     *
     * @throws DecryptionFailed
     */
    public function get(string $scope, MeshCredentialPurpose $purpose): ?string
    {
        $credential = $this->find($scope, $purpose);

        return $credential instanceof MeshCredential ? $this->reveal($credential) : null;
    }

    public function forget(string $scope, MeshCredentialPurpose $purpose): void
    {
        MeshCredential::query()->where('scope', $scope)->where('purpose', $purpose->value)->delete();
    }

    /**
     * Re-wraps the row's DEK from one KEK to another (rotate-kek); the caller saves.
     */
    public function rewrap(MeshCredential $credential, Envelope $from, Envelope $to): void
    {
        $credential->wrapped_dek = $from->rewrap($credential->wrapped_dek, $this->wrapContext($credential->scope, $credential->purpose), $to);
        $credential->kek_id = $to->kekId();
    }

    private function wrapContext(string $scope, MeshCredentialPurpose $purpose): string
    {
        return "mesh_credential:{$scope}:{$purpose->value}";
    }

    private function associatedData(string $scope, MeshCredentialPurpose $purpose): string
    {
        return "mesh_credential|{$scope}|{$purpose->value}";
    }
}
