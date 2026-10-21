<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Instance\RegisterSigningKey;
use App\Data\Audit\AuditActor;
use App\Services\Crypto\Exceptions\InvalidKeyFile;
use App\Services\Crypto\KeyFile;
use App\Services\Crypto\Signer;
use App\Services\Crypto\SigningKeyFile;
use App\Services\Registry\RegistryTokenKeyFile;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

#[Signature('launchroom:keys:generate')]
#[Description('Create the key-encryption key (KEK), control-plane signing key and registry token key files if they do not exist yet')]
final class GenerateKeysCommand extends Command
{
    public function handle(RegisterSigningKey $registerSigningKey): int
    {
        if (! $this->kek()) {
            return self::FAILURE;
        }

        $signer = $this->signingKey();

        if (! $signer instanceof Signer) {
            return self::FAILURE;
        }

        if (! $this->registryTokenKey()) {
            return self::FAILURE;
        }

        $this->register($signer, $registerSigningKey);

        return self::SUCCESS;
    }

    private function kek(): bool
    {
        $path = config()->string('launchroom.kek_path');

        if (file_exists($path)) {
            $this->components->info("KEK already exists at [{$path}]; leaving it untouched.");

            return true;
        }

        try {
            $key = KeyFile::create($path);
        } catch (InvalidKeyFile $e) {
            $this->components->error($e->getMessage());

            return false;
        }

        $this->components->info("KEK created at [{$path}] (id {$key->id()}, mode 0400).");
        $this->components->warn('Back this file up with your recovery kit: without it, stored secrets cannot be decrypted.');

        return true;
    }

    private function signingKey(): ?Signer
    {
        $path = config()->string('launchroom.signing_key_path');

        try {
            if (file_exists($path)) {
                $signer = SigningKeyFile::read($path);
                $this->components->info("Signing key already exists at [{$path}] (kid {$signer->kid}); leaving it untouched.");

                return $signer;
            }

            $signer = SigningKeyFile::create($path, 'cp-'.now()->format('Y-m-d'));
        } catch (InvalidKeyFile $e) {
            $this->components->error($e->getMessage());

            return null;
        }

        $this->components->info("Signing key created at [{$path}] (kid {$signer->kid}, mode 0400).");
        $this->line("  Public key: {$signer->publicKey()}");
        $this->components->warn('Back this file up with your recovery kit: servers pin its public key.');

        return $signer;
    }

    /**
     * The registry token key pair (docs/05 §6). An existing private key is
     * kept; its public key file (what Zot verifies with) is (re)written.
     */
    private function registryTokenKey(): bool
    {
        $path = config()->string('launchroom.registry.token_key_path');
        $publicPath = config()->string('launchroom.registry.token_public_key_path');

        try {
            if (file_exists($path)) {
                RegistryTokenKeyFile::writePublic(RegistryTokenKeyFile::read($path), $publicPath);
                $this->components->info("Registry token key already exists at [{$path}]; leaving it untouched.");

                return true;
            }

            $key = RegistryTokenKeyFile::create($path, $publicPath);
        } catch (InvalidKeyFile $e) {
            $this->components->error($e->getMessage());

            return false;
        }

        $this->components->info("Registry token key created at [{$path}] (kid {$key->kid()}, mode 0400).");
        $this->line("  Public key for the registry: {$publicPath}");

        return true;
    }

    /**
     * Record the public key in `signing_keys` when the database is ready.
     * During a fresh install this runs before migrations; the key is then
     * registered on first use (enrollment, bootstrap).
     */
    private function register(Signer $signer, RegisterSigningKey $registerSigningKey): void
    {
        try {
            if (! Schema::hasTable('signing_keys')) {
                return;
            }

            $registerSigningKey->handle($signer, AuditActor::system());
        } catch (QueryException) {
            $this->components->warn('Database not reachable; the signing key will be registered on first use.');
        }
    }
}
