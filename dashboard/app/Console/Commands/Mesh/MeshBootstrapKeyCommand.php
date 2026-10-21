<?php

declare(strict_types=1);

namespace App\Console\Commands\Mesh;

use App\Enums\Mesh\MeshCredentialPurpose;
use App\Models\Mesh\MeshCredential;
use App\Services\Mesh\MeshSecrets;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Hands the dashboard the first Headscale API key (docs/05 §2, docs/11 §2).
 * Only `headscale apikeys create` inside the Headscale container can mint it,
 * so the installer (and `make up-multi`) pipes it in on stdin, never argv.
 * It is written to `launchroom.mesh.bootstrap_key_path` (0600) and replaced
 * by a dashboard-owned, envelope-encrypted key when the mesh is enabled.
 *
 * `--check` exits 0 when a key is needed (none stored, no bootstrap file), 1 otherwise.
 */
#[Signature('launchroom:mesh:bootstrap-key {--check : Only report whether a bootstrap key is needed}')]
#[Description('Store the first Headscale API key (read from stdin) for the dashboard to bootstrap the mesh')]
final class MeshBootstrapKeyCommand extends Command
{
    public function handle(MeshSecrets $secrets): int
    {
        $path = config()->string('launchroom.mesh.bootstrap_key_path');
        $needed = ! $secrets->find(MeshSecrets::instanceScope(), MeshCredentialPurpose::HeadscaleApiKey) instanceof MeshCredential
            && (! is_file($path) || filesize($path) <= 0);

        if ($this->option('check') === true) {
            return $needed ? self::SUCCESS : self::FAILURE;
        }

        if (! $needed) {
            $this->components->info('The dashboard already has a Headscale API key.');

            return self::SUCCESS;
        }

        $key = trim((string) stream_get_contents(STDIN, 4096));

        if (preg_match('/^[\x21-\x7e]{16,1024}$/D', $key) !== 1) {
            $this->components->error('Expected a Headscale API key on stdin.');

            return self::FAILURE;
        }

        $old = umask(0o077);

        try {
            if (file_put_contents($path, $key."\n") === false) {
                $this->components->error("Could not write {$path}.");

                return self::FAILURE;
            }
        } finally {
            umask($old);
        }

        chmod($path, 0o600);
        $this->components->info('Headscale bootstrap key stored.');

        return self::SUCCESS;
    }
}
