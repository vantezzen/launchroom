<?php

declare(strict_types=1);

namespace App\Drivers\DatabaseEngines;

use App\Data\Services\HealthcheckSettings;
use App\Enums\ConnectionStyle;
use App\Enums\DatabaseEngine;
use App\Enums\RemoteAccess\RemoteAccessProtocol;
use App\Enums\VariableType;
use App\Support\Images\ImageReference;

/**
 * MongoDB (D47). The official image creates the root user from
 * MONGO_INITDB_ROOT_* on the first start (and then enables auth), so the
 * generated user is the database's superuser like Postgres' POSTGRES_USER;
 * clients authenticate against `admin` (`authSource=admin`).
 *
 * The entrypoint drops to the mongodb user with gosu and execs mongod, so
 * mongod is PID 1 and gets SIGTERM directly (clean shutdown, D42).
 *
 * MongoDB 8.0 refuses to start on Linux 6.19 and newer (SERVER-121912,
 * tcmalloc's rseq use); 7.0 is offered for such servers until a fixed 8.0
 * release is pinned here.
 */
final readonly class MongoDb implements DatabaseEngineDriver
{
    public function __construct(
        private ConfiguredImages $images,
    ) {}

    public function engine(): DatabaseEngine
    {
        return DatabaseEngine::MongoDb;
    }

    public function versions(): array
    {
        return $this->images->versions($this->engine());
    }

    public function defaultVersion(): string
    {
        return $this->images->defaultVersion($this->engine());
    }

    public function image(string $version): ImageReference
    {
        return $this->images->for($this->engine(), $version);
    }

    public function port(): int
    {
        return 27017;
    }

    public function category(): DatabaseEngineCategory
    {
        return DatabaseEngineCategory::Document;
    }

    public function volumeMountPath(string $version): string
    {
        return '/data/db';
    }

    public function dataDirectory(string $version): string
    {
        return $this->volumeMountPath($version);
    }

    public function variables(): array
    {
        return [
            new DriverVariable('MONGO_INITDB_ROOT_USERNAME', 'app'),
            new DriverVariable('MONGO_INITDB_ROOT_PASSWORD', '${{ secret(32) }}', VariableType::Secret),
            new DriverVariable('MONGO_INITDB_DATABASE', 'app'),
            new DriverVariable('MONGOHOST', '${{PRIVATE_HOST}}'),
            new DriverVariable('MONGOPORT', '27017'),
            new DriverVariable('MONGOUSER', '${{MONGO_INITDB_ROOT_USERNAME}}'),
            new DriverVariable('MONGOPASSWORD', '${{MONGO_INITDB_ROOT_PASSWORD}}'),
            new DriverVariable('MONGODATABASE', '${{MONGO_INITDB_DATABASE}}'),
            new DriverVariable('MONGO_URL', 'mongodb://${{MONGO_INITDB_ROOT_USERNAME}}:${{MONGO_INITDB_ROOT_PASSWORD}}@${{PRIVATE_HOST}}:27017/${{MONGO_INITDB_DATABASE}}?authSource=admin'),
            new DriverVariable('DATABASE_URL', '${{MONGO_URL}}'),
        ];
    }

    public function exportedKeys(): array
    {
        return ['MONGO_URL', 'DATABASE_URL', 'MONGOHOST', 'MONGOPORT', 'MONGOUSER', 'MONGOPASSWORD', 'MONGODATABASE'];
    }

    public function exportedKey(ConnectionPart $part): ?string
    {
        return match ($part) {
            ConnectionPart::Url => 'MONGO_URL',
            ConnectionPart::Host => 'MONGOHOST',
            ConnectionPart::Port => 'MONGOPORT',
            ConnectionPart::User => 'MONGOUSER',
            ConnectionPart::Password => 'MONGOPASSWORD',
            ConnectionPart::Database => 'MONGODATABASE',
            ConnectionPart::NativePort => null,
        };
    }

    public function imageEnvAliases(): array
    {
        return [
            'user' => ['MONGO_INITDB_ROOT_USERNAME', 'MONGODB_ROOT_USER', 'MONGODB_USERNAME'],
            'password' => ['MONGO_INITDB_ROOT_PASSWORD', 'MONGODB_ROOT_PASSWORD', 'MONGODB_PASSWORD'],
            'database' => ['MONGO_INITDB_DATABASE', 'MONGODB_DATABASE'],
        ];
    }

    public function nativeSchemes(): array
    {
        return [];
    }

    public function urlQuery(): array
    {
        return ['authSource' => 'admin'];
    }

    public function connectionVariables(ConnectionStyle $style, string $slug): array
    {
        return match ($style) {
            ConnectionStyle::Url => ['MONGO_URL' => "\${{{$slug}.MONGO_URL}}"],
            // mongodb/laravel-mongodb reads MONGODB_URI and MONGODB_DATABASE.
            ConnectionStyle::Laravel => [
                'DB_CONNECTION' => 'mongodb',
                'MONGODB_URI' => "\${{{$slug}.MONGO_URL}}",
                'MONGODB_DATABASE' => "\${{{$slug}.MONGODATABASE}}",
            ],
            ConnectionStyle::Parts => [
                'MONGOHOST' => "\${{{$slug}.MONGOHOST}}",
                'MONGOPORT' => "\${{{$slug}.MONGOPORT}}",
                'MONGOUSER' => "\${{{$slug}.MONGOUSER}}",
                'MONGOPASSWORD' => "\${{{$slug}.MONGOPASSWORD}}",
                'MONGODATABASE' => "\${{{$slug}.MONGODATABASE}}",
            ],
        };
    }

    public function health(): HealthcheckSettings
    {
        // The init phase listens on 127.0.0.1 only, so the TCP check passes
        // once the real server is up.
        return HealthcheckSettings::tcp(startPeriodSeconds: 60, intervalSeconds: 10, failureThreshold: 5);
    }

    public function capabilities(): array
    {
        // The entrypoint chowns /data/db and drops to the mongodb user (gosu).
        return ['CHOWN', 'SETUID', 'SETGID', 'DAC_OVERRIDE', 'FOWNER'];
    }

    public function stopSignal(): string
    {
        // mongod shuts down cleanly on SIGTERM (flushes and closes WiredTiger).
        return 'SIGTERM';
    }

    public function stopTimeout(): string
    {
        return '60s';
    }

    public function entrypoint(): ?array
    {
        return null;
    }

    public function args(string $version): ?array
    {
        return null;
    }

    public function client(): ?string
    {
        return null;
    }

    public function phpExtension(): string
    {
        return 'mongodb';
    }

    public function exportFileExtension(): string
    {
        return 'archive';
    }

    public function importFormat(): DumpImport
    {
        return new DumpImport(
            engine: $this->engine(),
            formats: ['mongodb_archive'],
            formatsHint: 'mongodump --archive, optionally gzipped (.archive, .gz)',
            urlSchemes: ['mongodb', 'mongodb+srv'],
            urlExample: 'mongodb://user:password@db.example.com:27017/app',
            objects: 'collections',
        );
    }

    public function restorable(): bool
    {
        return true;
    }

    public function pointInTimeRecoveryFrom(): null
    {
        return null;
    }

    public function remoteAccess(): ?RemoteAccessProtocol
    {
        return null;
    }
}
