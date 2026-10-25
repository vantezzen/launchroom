<?php

declare(strict_types=1);

namespace App\Services\Transfers;

use App\Data\Services\DatabaseSource;
use App\Data\Transfers\DatabasePreparationData;
use App\Enums\Backups\BackupSubject;
use App\Enums\DaemonFeature;
use App\Enums\DatabaseEngine;
use App\Enums\ServerStatus;
use App\Enums\Transfers\DataSubject;
use App\Exceptions\DomainRuleViolation;
use App\Models\Placement;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Volume;
use App\Services\Backups\BackupSubjects;
use App\Services\Backups\DatabaseWorkloads;

/**
 * Where an import or export of a service instance runs (docs/14 §12.2): the
 * database's serving placement, or the server that holds a volume, and
 * whether that server's launchroomd can do it.
 */
final readonly class ImportTarget
{
    /** Engines a dump can be imported into (ClickHouse, Redis and Valkey can't, docs/14 §12). */
    public const array IMPORTABLE = [DatabaseEngine::Postgres, DatabaseEngine::MySql, DatabaseEngine::MongoDb];

    private function __construct(
        public ServiceInstance $instance,
        public ?DataSubject $subject,
        public ?DatabaseSource $database,
    ) {}

    public static function for(ServiceInstance $instance): self
    {
        $instance->loadMissing(['service', 'volumes']);
        $database = DatabaseWorkloads::source($instance);
        $subject = BackupSubjects::for($instance);

        return new self(
            $instance,
            $database instanceof DatabaseSource ? DataSubject::Database : ($subject === BackupSubject::Volume ? DataSubject::Volume : null),
            $database,
        );
    }

    public function importSupported(): bool
    {
        return $this->subject === DataSubject::Volume
            || ($this->database instanceof DatabaseSource && in_array($this->database->engine, self::IMPORTABLE, true));
    }

    /**
     * The running database's placement (with its server), or a violation.
     */
    public function placement(string $doing = 'import data'): Placement
    {
        // Also a database started alone for an import before its first deploy (docs/14 §12.9).
        $placement = DatabasePreparations::importPlacement($this->instance);

        if (! $placement instanceof Placement || ! $this->database instanceof DatabaseSource) {
            throw DomainRuleViolation::on('service', DatabasePreparations::preparable($this->instance)
                ? "{$this->instance->service->name} isn’t running yet. Start it for the import first."
                : "{$this->instance->service->name} isn’t running. Deploy it first, then {$doing}.");
        }

        $this->assertServer($placement->server, $doing);

        return $placement;
    }

    /**
     * A volume of this instance by public id (null: the only one).
     */
    public function volume(?string $publicId, string $doing = 'import files'): Volume
    {
        $volumes = $this->instance->volumes;
        $volume = $publicId === null ? ($volumes->count() === 1 ? $volumes->first() : null) : $volumes->firstWhere('public_id', $publicId);

        if (! $volume instanceof Volume) {
            throw DomainRuleViolation::on('volume', 'Pick one of this service’s volumes.');
        }

        $volume->loadMissing('server');

        if (! $volume->server instanceof Server) {
            throw DomainRuleViolation::on('volume', 'This volume is created on the next deploy. Deploy the service first.');
        }

        $this->assertServer($volume->server, $doing);

        return $volume;
    }

    /**
     * Why import/export can't run now, or null (for the UI).
     */
    public function unavailableReason(): ?string
    {
        try {
            // A database never deployed is started alone for the import (docs/14 §12.9).
            if ($this->subject === DataSubject::Database && DatabasePreparations::view($this->instance) instanceof DatabasePreparationData) {
                return null;
            }

            if ($this->subject === DataSubject::Database) {
                $this->placement();
            } elseif ($this->subject === DataSubject::Volume) {
                foreach ($this->instance->volumes as $volume) {
                    $volume->loadMissing('server');

                    if ($volume->server instanceof Server) {
                        $this->assertServer($volume->server, 'import data');
                    }
                }
            }
        } catch (DomainRuleViolation $e) {
            return $e->getMessage();
        }

        return null;
    }

    public function engine(): string
    {
        return $this->database instanceof DatabaseSource ? DatabaseWorkloads::daemonEngine($this->database) : '';
    }

    /**
     * The protocol engine of an importable database.
     *
     * @return 'postgres'|'mysql'|'mongodb'
     */
    public function importEngine(): string
    {
        return match ($this->database?->engine) {
            DatabaseEngine::MySql => 'mysql',
            DatabaseEngine::MongoDb => 'mongodb',
            DatabaseEngine::Postgres => 'postgres',
            default => throw DomainRuleViolation::on('service', 'Importing into this database isn’t supported.'),
        };
    }

    /**
     * `<service>-<YYYYMMDD-HHmm>.<ext>` in the engine's native format (docs/14 §12.5).
     */
    public function exportFileName(): string
    {
        $extension = $this->subject === DataSubject::Volume ? 'tar.gz' : match ($this->database?->engine) {
            DatabaseEngine::Postgres => 'dump',
            DatabaseEngine::MySql => 'sql.gz',
            DatabaseEngine::MongoDb => 'archive',
            DatabaseEngine::ClickHouse => 'zip',
            DatabaseEngine::Redis, DatabaseEngine::Valkey => 'rdb',
            null => 'bin',
        };

        return $this->instance->service->slug.'-'.now()->utc()->format('Ymd-Hi').'.'.$extension;
    }

    private function assertServer(Server $server, string $doing): void
    {
        if (! $server->supportsDaemonFeature(DaemonFeature::DataTransfer)) {
            throw DomainRuleViolation::on('server', "Update {$server->name} in Settings → Updates to {$doing}.");
        }

        if ($server->status !== ServerStatus::Online) {
            throw DomainRuleViolation::on('server', "{$server->name} is offline. Try again once it is back.");
        }
    }
}
