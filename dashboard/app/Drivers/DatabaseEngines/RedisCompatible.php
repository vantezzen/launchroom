<?php

declare(strict_types=1);

namespace App\Drivers\DatabaseEngines;

use App\Data\Services\HealthcheckSettings;
use App\Enums\ConnectionStyle;
use App\Enums\VariableType;
use App\Support\Images\ImageReference;

/**
 * Redis and Valkey share everything but the image and binary names.
 *
 * Neither image reads a password from the environment, and a password in
 * argv would be plaintext in the (unsealed) NodeState. So the driver-owned,
 * fixed args feed `requirepass` through stdin (`<server> -` reads its config
 * from stdin) from the sealed REDIS_PASSWORD env, re-entering the image
 * entrypoint so it still drops privileges. No user input reaches this string.
 */
abstract readonly class RedisCompatible implements DatabaseEngineDriver
{
    public function __construct(
        private ConfiguredImages $images,
    ) {}

    abstract protected function binary(): string;

    public function versions(): array
    {
        return $this->images->versions($this->engine());
    }

    public function image(string $version): ImageReference
    {
        return $this->images->for($this->engine(), $version);
    }

    public function port(): int
    {
        return 6379;
    }

    public function volumeMountPath(string $version): string
    {
        return '/data';
    }

    public function variables(): array
    {
        return [
            new DriverVariable('REDIS_PASSWORD', '${{ secret(32) }}', VariableType::Secret),
            new DriverVariable('REDISCLI_AUTH', '${{REDIS_PASSWORD}}'),
            new DriverVariable('REDIS_HOST', '${{PRIVATE_HOST}}'),
            new DriverVariable('REDIS_PORT', '6379'),
            new DriverVariable('REDIS_URL', 'redis://default:${{REDIS_PASSWORD}}@${{PRIVATE_HOST}}:6379'),
        ];
    }

    public function exportedKeys(): array
    {
        return ['REDIS_URL', 'REDIS_HOST', 'REDIS_PORT', 'REDIS_PASSWORD'];
    }

    public function connectionVariables(ConnectionStyle $style, string $slug): array
    {
        return match ($style) {
            ConnectionStyle::Url => ['REDIS_URL' => "\${{{$slug}.REDIS_URL}}"],
            ConnectionStyle::Laravel, ConnectionStyle::Parts => [
                'REDIS_HOST' => "\${{{$slug}.REDIS_HOST}}",
                'REDIS_PORT' => "\${{{$slug}.REDIS_PORT}}",
                'REDIS_PASSWORD' => "\${{{$slug}.REDIS_PASSWORD}}",
            ],
        };
    }

    public function health(): HealthcheckSettings
    {
        return HealthcheckSettings::tcp(startPeriodSeconds: 20, intervalSeconds: 5, failureThreshold: 5);
    }

    public function capabilities(): array
    {
        return ['CHOWN', 'SETUID', 'SETGID', 'DAC_OVERRIDE', 'FOWNER'];
    }

    public function stopSignal(): string
    {
        // Saves the dataset (and fsyncs the AOF) before exiting.
        return 'SIGTERM';
    }

    public function entrypoint(): ?array
    {
        return null;
    }

    public function args(string $version): ?array
    {
        $binary = $this->binary();

        return ['sh', '-c', "exec docker-entrypoint.sh {$binary} - --appendonly yes <<EOF\nrequirepass \$REDIS_PASSWORD\nEOF\n"];
    }

    public function dumpCommand(): array
    {
        // REDISCLI_AUTH comes from the env.
        return [str_replace('-server', '-cli', $this->binary()), '--rdb', '-'];
    }

    public function restoreCommand(): array
    {
        // Restores replace the RDB file in the volume while stopped (backup wave).
        return [];
    }

    public function dumpFileExtension(): string
    {
        return 'rdb';
    }
}
