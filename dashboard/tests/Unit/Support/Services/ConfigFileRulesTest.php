<?php

declare(strict_types=1);

use App\Data\Services\ConfigFile;
use App\Support\Services\ConfigFileRules;

test('config files must be clean absolute paths outside kernel trees, Docker files and volumes', function (string $path, ?string $problem): void {
    $errors = ConfigFileRules::errors([new ConfigFile($path, "x\n")], ['/var/lib/postgresql/data']);

    $problem === null ? expect($errors)->toBe([]) : expect($errors[0] ?? '')->toContain($problem);
})->with([
    'init sql' => ['/docker-entrypoint-initdb.d/init-scripts/99-roles.sql', null],
    'relative' => ['etc/app.yml', 'isn’t a valid file path'],
    'dot dot' => ['/etc/../root/x', 'isn’t a valid file path'],
    'proc' => ['/proc/self/x', 'Docker and the kernel'],
    'hosts' => ['/etc/hosts', 'Docker and the kernel'],
    'in a volume' => ['/var/lib/postgresql/data/postgresql.auto.conf', 'inside the volume'],
    'the volume itself' => ['/var/lib/postgresql/data', 'inside the volume'],
]);

test('config files are limited in count and size, are text and hold no secrets', function (): void {
    $many = array_map(static fn (int $i): ConfigFile => new ConfigFile("/etc/app/{$i}.conf", ''), range(1, ConfigFileRules::MAX_FILES + 1));

    expect(ConfigFileRules::errors($many)[0])->toContain('at most 32')
        ->and(ConfigFileRules::errors([new ConfigFile('/a', str_repeat('x', ConfigFileRules::MAX_BYTES + 1))])[0])->toContain('larger than 64 KiB')
        ->and(ConfigFileRules::errors([new ConfigFile('/a', "x\0y")])[0])->toContain('must be text')
        ->and(ConfigFileRules::errors([new ConfigFile('/a', "\xff\xfe")])[0])->toContain('must be text')
        ->and(ConfigFileRules::errors([new ConfigFile('/a', "-----BEGIN PRIVATE KEY-----\nMIIE\n-----END PRIVATE KEY-----\n")])[0])->toContain('secret')
        ->and(ConfigFileRules::errors([new ConfigFile('/a', 'x'), new ConfigFile('/a', 'y')])[0])->toContain('defined twice')
        ->and(ConfigFileRules::errors([new ConfigFile('/etc/app', 'x'), new ConfigFile('/etc/app/x.conf', 'y')])[0])->toContain('can’t be a file and the folder');
});
