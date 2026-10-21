<?php

declare(strict_types=1);

use App\Data\Blueprints\BlueprintService;
use App\Services\Blueprints\EngineVersions;
use App\Support\Blueprints\BlueprintValidator;
use App\Support\Compose\ComposeRejected;
use App\Support\Compose\ComposeTranslator;
use App\Support\Compose\TranslationOptions;
use Symfony\Component\Yaml\Yaml;

/*
| Property tests (docs/17 §5): whatever the input, the translator either
| rejects the file as a whole or returns a valid blueprint, and nothing it
| refuses ever reaches that blueprint.
*/

function bpFuzzOptions(int $seed): TranslationOptions
{
    return new TranslationOptions(
        values: $seed % 3 === 0 ? ['DB_PASSWORD' => 'x', 'PORT' => '8080'] : [],
        files: $seed % 2 === 0 ? ['.env' => "A=1\n"] : [],
        convertBindMounts: $seed % 2 === 1,
        repository: $seed % 4 !== 0,
        engines: resolve(EngineVersions::class)->all(),
    );
}

function bpAssertSafe(BlueprintService $service): void
{
    expect(array_diff($service->capabilities, BlueprintValidator::CAPABILITIES))->toBe([]);

    foreach ($service->volumes as $path) {
        expect($path)->toStartWith('/')
            ->and($path)->not->toBe('/')
            ->and(str_contains($path, 'docker.sock'))->toBeFalse()
            ->and(preg_match('#^/(proc|sys|dev)(/|$)#', $path))->toBe(0);
    }

    foreach ([...($service->startCommand ?? []), ...($service->entrypoint ?? [])] as $word) {
        expect($word)->toBeString();
    }

    // Refused settings have no place to go: only the blueprint's own fields remain.
    $array = $service->toArray();
    unset($array['notes'], $array['variables']);
    $json = (string) json_encode($array);

    foreach (['"privileged"', '"network_mode"', '"pid"', '"devices"', '"security_opt"', '"sysctls"'] as $field) {
        expect(str_contains($json, $field))->toBeFalse();
    }
}

function bpTranslateOrReject(string $yaml, TranslationOptions $options): void
{
    try {
        $translation = ComposeTranslator::translate($yaml, $options);
    } catch (ComposeRejected) {
        expect(true)->toBeTrue();

        return;
    }

    expect(BlueprintValidator::errors($translation->blueprint))->toBe([]);

    foreach ($translation->blueprint->services as $service) {
        bpAssertSafe($service);
    }
}

test('mutated real-world files never crash the translator', function (string $fixture): void {
    $original = (string) file_get_contents(__DIR__.'/../../Fixtures/compose/'.$fixture);
    mt_srand(crc32($fixture));
    $noise = ['', ' ', ':', '-', "\n", "\t", '"', "'", '{', '}', '[', ']', '*', '&', '!', '$', '${', '#', 'privileged: true', '/', '..', 'host', '0', '99999', '<<'];

    for ($run = 0; $run < 120; $run++) {
        $lines = explode("\n", $original);

        for ($edit = 0, $edits = mt_rand(1, 6); $edit < $edits; $edit++) {
            $index = mt_rand(0, count($lines) - 1);
            $line = $lines[$index];

            $lines[$index] = match (mt_rand(0, 4)) {
                0 => substr($line, 0, mt_rand(0, strlen($line))),
                1 => substr_replace($line, $noise[mt_rand(0, count($noise) - 1)], mt_rand(0, strlen($line)), 0),
                2 => '',
                3 => str_repeat(' ', mt_rand(0, 8)).ltrim($line),
                default => $lines[mt_rand(0, count($lines) - 1)],
            };
        }

        bpTranslateOrReject(implode("\n", $lines), bpFuzzOptions($run));
    }
})->with(array_map(basename(...), glob(__DIR__.'/../../Fixtures/compose/*.yml') ?: []));

/**
 * @param  list<mixed>  $scalars
 * @param  list<string>  $keys
 */
function bpRandomValue(int $depth, array $scalars, array $keys): mixed
{
    return match ($depth > 2 ? 0 : mt_rand(0, 3)) {
        0, 1 => $scalars[mt_rand(0, count($scalars) - 1)],
        2 => array_map(static fn (): mixed => bpRandomValue($depth + 1, $scalars, $keys), range(0, mt_rand(0, 3))),
        default => array_combine(
            array_map(static fn (): string => $keys[mt_rand(0, count($keys) - 1)].(mt_rand(0, 3) === 0 ? 'x' : ''), range(0, 3)),
            array_map(static fn (): mixed => bpRandomValue($depth + 1, $scalars, $keys), range(0, 3)),
        ),
    };
}

test('random Compose-shaped documents always translate safely', function (): void {
    mt_srand(4242);
    $scalars = ['nginx:1.29', 'postgres:17', 'redis:7', 'mariadb:11', 'ghcr.io/o/a:1', 'quay.io/x/y', '', '0', 80, 3000, true, null, '${X}', '${X:-y}', '$$', '/', './data', '/var/run/docker.sock:/s', '/etc/passwd:/etc/passwd', 'a:b:c:d', '127.0.0.1:80:80', '80/udp', 'curl -f http://localhost:80/', 'a && b', 'SYS_ADMIN', 'CHOWN', '1g', '0.5', '-1', '..', str_repeat('x', 300)];
    $keys = ['image', 'build', 'command', 'entrypoint', 'ports', 'expose', 'environment', 'env_file', 'volumes', 'depends_on', 'healthcheck', 'deploy', 'cap_add', 'privileged', 'network_mode', 'pid', 'devices', 'user', 'labels', 'restart', 'x-launchroom', 'profiles', 'scale', 'cpus', 'mem_limit', 'secrets'];

    for ($run = 0; $run < 400; $run++) {
        $services = [];

        for ($i = 0, $n = mt_rand(1, 5); $i < $n; $i++) {
            $definition = [];

            foreach (array_rand(array_flip($keys), mt_rand(2, 8)) as $key) {
                $definition[$key] = bpRandomValue(1, $scalars, $keys);
            }

            $services['svc_'.$i.(mt_rand(0, 1) === 1 ? '-X' : '')] = $definition;
        }

        bpTranslateOrReject(Yaml::dump(['services' => $services, 'volumes' => ['data' => null]], 6), bpFuzzOptions($run));
    }
});
