<?php

declare(strict_types=1);

namespace App\Services\Mesh;

use App\Enums\Mesh\MeshCredentialPurpose;
use App\Exceptions\DecryptionFailed;
use App\Models\Mesh\MeshCredential;
use App\Models\Server;
use App\Services\Crypto\SealedColumns;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use SensitiveParameter;

/**
 * Mesh secrets: the Headscale API key, the "bring your own Tailscale" auth
 * key and per-server pre-auth keys, one sealed row per scope and purpose
 * (App\Services\Crypto\SealedColumns).
 */
final readonly class MeshSecrets
{
    public function __construct(
        private SealedColumns $sealed,
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
        $credential = $this->find($scope, $purpose) ?? new MeshCredential;
        $credential->scope = $scope;
        $credential->purpose = $purpose;
        $credential->server_id = $server?->id;
        $this->sealed->seal($credential, 'ciphertext', $value, $this->sealed->newKey($credential));
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
        return $this->sealed->reveal($credential, 'ciphertext') ?? throw DecryptionFailed::malformed();
    }

    /**
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
}
