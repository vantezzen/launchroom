<?php

declare(strict_types=1);

namespace App\Actions\Registry;

use App\Enums\Registry\RegistryCredentialStatus;
use App\Models\Registry\RegistryCredential;
use App\Services\Crypto\SealedColumns;
use App\Services\Registry\UpstreamAuth;
use App\Services\Registry\UpstreamRegistry;
use App\Services\Registry\UpstreamRegistryError;

/**
 * Signs in to the registry with the stored details (`GET /v2/` and its
 * token flow) and records the outcome on the credential. Returns the
 * error message, or null when the sign-in worked. The caller authorizes.
 */
final readonly class CheckRegistryCredential
{
    public function __construct(
        private SealedColumns $sealed,
        private UpstreamRegistry $upstream,
    ) {}

    public function handle(RegistryCredential $credential): ?string
    {
        try {
            $this->upstream->checkSignIn(new UpstreamAuth($credential->host, $credential->username, (string) $this->sealed->reveal($credential, 'secret')));
        } catch (UpstreamRegistryError $e) {
            $credential->markChecked(RegistryCredentialStatus::Failed, $e->getMessage());

            return $e->getMessage();
        }

        $credential->markChecked(RegistryCredentialStatus::Ok);

        return null;
    }
}
