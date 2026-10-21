<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Services\Backups\ControlPlaneKeyBundle;

function headscaleStateDir(): string
{
    $dir = sys_get_temp_dir().'/lr-headscale-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700);

    return $dir;
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/lr-headscale-*') ?: [] as $dir) {
        array_map(unlink(...), glob($dir.'/*') ?: []);
        rmdir($dir);
    }
});

test('the key bundle carries Headscale keys and database, base64 encoded, and parses them back', function (): void {
    $dir = headscaleStateDir();
    file_put_contents($dir.'/noise_private.key', 'privkey:aaaa');
    file_put_contents($dir.'/derp_server_private.key', 'privkey:bbbb');
    file_put_contents($dir.'/db.sqlite', "SQLite format 3\0\xff\xfe binary");
    file_put_contents($dir.'/db.sqlite-wal', "\x37\x7f\x06\x82 wal");
    file_put_contents($dir.'/db.sqlite-shm', 'shm is never copied');
    file_put_contents($dir.'/other.txt', 'not part of the state');
    config(['launchroom.mesh.headscale_state_path' => $dir]);

    $state = ControlPlaneKeyBundle::headscale();

    expect(array_keys($state))->toBe(['noise_private.key', 'derp_server_private.key', 'db.sqlite', 'db.sqlite-wal'])
        ->and($state['db.sqlite'])->toBe("SQLite format 3\0\xff\xfe binary");

    $parsed = ControlPlaneKeyBundle::parse(json_encode([
        'format' => ControlPlaneKeyBundle::FORMAT,
        'env' => [],
        'files' => [],
        'headscale' => [...array_map(base64_encode(...), $state), '../escape' => base64_encode('x'), 'db.sqlite-shm' => base64_encode('x')],
    ], JSON_THROW_ON_ERROR));

    expect($parsed['headscale'])->toBe($state);
});

test('older bundles without Headscale state still parse', function (): void {
    $parsed = ControlPlaneKeyBundle::parse(json_encode(['format' => ControlPlaneKeyBundle::FORMAT, 'env' => [], 'files' => []], JSON_THROW_ON_ERROR));

    expect($parsed['headscale'])->toBe([]);
});

test('no state path or no Headscale state means nothing to include while the mesh is off', function (): void {
    config(['launchroom.mesh.headscale_state_path' => null]);
    expect(ControlPlaneKeyBundle::headscale())->toBe([]);

    config(['launchroom.mesh.headscale_state_path' => headscaleStateDir(), 'launchroom.mesh.headscale_url' => 'http://headscale:8080']);
    expect(ControlPlaneKeyBundle::headscale())->toBe([]);
});

test('with the embedded mesh on, missing Headscale state fails the backup', function (): void {
    config(['launchroom.mesh.headscale_state_path' => headscaleStateDir(), 'launchroom.mesh.headscale_url' => 'http://headscale:8080']);
    InstanceSettings::current()->forceFill(['mesh_enabled_at' => now()])->save();

    expect(fn (): array => ControlPlaneKeyBundle::headscale())->toThrow(RuntimeException::class, 'Headscale');
});

test('oversized Headscale state is refused', function (): void {
    $dir = headscaleStateDir();
    file_put_contents($dir.'/noise_private.key', 'privkey:aaaa');
    $handle = fopen($dir.'/db.sqlite', 'w');
    ftruncate($handle, ControlPlaneKeyBundle::HEADSCALE_MAX_BYTES + 1);
    fclose($handle);
    config(['launchroom.mesh.headscale_state_path' => $dir]);

    expect(fn (): array => ControlPlaneKeyBundle::headscale())->toThrow(RuntimeException::class, 'larger than');
});
