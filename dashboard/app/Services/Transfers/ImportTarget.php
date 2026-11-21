<?php

declare(strict_types=1);

namespace App\Services\Transfers;

use App\Data\Services\DatabaseSource;
use App\Data\Transfers\DatabasePreparationData;
use App\Drivers\DatabaseEngines\DatabaseEngineDriver;
use App\Drivers\DatabaseEngines\DatabaseEngines;
use App\Drivers\DatabaseEngines\DumpImport;
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
use App\Support\Refusal;

/**
 * Where an import or export of a service instance runs (docs/14 §12.2): the
 * database's serving placement, or the server that holds a volume, and
 * whether that server's launchroomd can do it.
 */
final readonly class ImportTarget
{
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
            || ($this->database instanceof DatabaseSource && self::accepts($this->database->engine));
    }

    public static function accepts(DatabaseEngine $engine): bool
    {
        return self::importFor($engine) instanceof DumpImport;
    }

    /** How $engine takes an import, or null when it takes none. */
    public static function importFor(DatabaseEngine $engine): ?DumpImport
    {
        return self::driver($engine)->importFormat();
    }

    /**
     * The running database's placement (with its server), or a violation.
     */
    public function placement(string $doing = 'import data'): Placement
    {
        return Refusal::orThrow($this->servingPlacement($doing));
    }

    private function servingPlacement(string $doing): Placement|Refusal
    {
        // Also a database started alone for an import before its first deploy (docs/14 §12.9).
        $placement = DatabasePreparations::importPlacement($this->instance);

        if (! $placement instanceof Placement || ! $this->database instanceof DatabaseSource) {
            return Refusal::on('service', DatabasePreparations::preparable($this->instance)
                ? "{$this->instance->service->name} isn’t running yet. Start it for the import first."
                : "{$this->instance->service->name} isn’t running. Deploy it first, then {$doing}.");
        }

        return $this->serverRefusal($placement->server, $doing) ?? $placement;
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

        return Refusal::orThrow($this->serverRefusal($volume->server, $doing) ?? $volume);
    }

    /**
     * Why import/export can't run now, or null (for the UI).
     */
    public function unavailableReason(): ?string
    {
        // A database never deployed is started alone for the import (docs/14 §12.9).
        if ($this->subject === DataSubject::Database && DatabasePreparations::view($this->instance) instanceof DatabasePreparationData) {
            return null;
        }

        if ($this->subject === DataSubject::Database) {
            $placement = $this->servingPlacement('import data');

            return $placement instanceof Refusal ? $placement->message : null;
        }

        if ($this->subject === DataSubject::Volume) {
            foreach ($this->instance->volumes as $volume) {
                $volume->loadMissing('server');
                $refusal = $volume->server instanceof Server ? $this->serverRefusal($volume->server, 'import data') : null;

                if ($refusal instanceof Refusal) {
                    return $refusal->message;
                }
            }
        }

        return null;
    }

    /** The protocol engine of the database (exports run for every engine). */
    public function engine(): string
    {
        return $this->database?->engine->value ?? '';
    }

    /**
     * How the database takes an import, or a violation when it can't.
     */
    public function import(): DumpImport
    {
        $import = $this->database instanceof DatabaseSource ? self::driver($this->database->engine)->importFormat() : null;

        return $import ?? throw DomainRuleViolation::on('service', 'Importing into this database isn’t supported.');
    }

    public function importEngine(): string
    {
        return $this->import()->engine->value;
    }

    /**
     * `<service>-<YYYYMMDD-HHmm>.<ext>` in the engine's native format (docs/14 §12.5).
     */
    public function exportFileName(): string
    {
        $extension = match (true) {
            $this->subject === DataSubject::Volume => 'tar.gz',
            $this->database instanceof DatabaseSource => self::driver($this->database->engine)->exportFileExtension(),
            default => 'bin',
        };

        return $this->instance->service->slug.'-'.now()->utc()->format('Ymd-Hi').'.'.$extension;
    }

    private static function driver(DatabaseEngine $engine): DatabaseEngineDriver
    {
        return resolve(DatabaseEngines::class)->for($engine);
    }

    private function serverRefusal(Server $server, string $doing): ?Refusal
    {
        if (! $server->supportsDaemonFeature(DaemonFeature::DataTransfer)) {
            return Refusal::on('server', "Update {$server->name} in Settings → Updates to {$doing}.");
        }

        if ($server->status !== ServerStatus::Online) {
            return Refusal::on('server', "{$server->name} is offline. Try again once it is back.");
        }

        return null;
    }
}
