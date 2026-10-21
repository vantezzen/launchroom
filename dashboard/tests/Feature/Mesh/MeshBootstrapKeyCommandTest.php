<?php

declare(strict_types=1);

use App\Enums\Mesh\MeshCredentialPurpose;
use App\Services\Mesh\MeshSecrets;

test('the bootstrap key is only requested while the dashboard has none', function (): void {
    $path = sys_get_temp_dir().'/hs-bootstrap-'.bin2hex(random_bytes(4));
    config(['launchroom.mesh.bootstrap_key_path' => $path]);

    $this->artisan('launchroom:mesh:bootstrap-key', ['--check' => true])->assertExitCode(0);

    file_put_contents($path, "hskey-api-ABCDEFGHIJKL-secret\n");
    $this->artisan('launchroom:mesh:bootstrap-key', ['--check' => true])->assertExitCode(1);

    unlink($path);
    resolve(MeshSecrets::class)->put(MeshSecrets::instanceScope(), MeshCredentialPurpose::HeadscaleApiKey, 'hskey-api-ABCDEFGHIJKL-secret');
    $this->artisan('launchroom:mesh:bootstrap-key', ['--check' => true])->assertExitCode(1);
});
