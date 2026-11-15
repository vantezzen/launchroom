<?php

declare(strict_types=1);

namespace App\Data\Servers;

use App\Enums\ReplacedServiceKind;
use App\Enums\ReplacedServiceStatus;
use Spatie\LaravelData\Data;

/**
 * One service of `server_replacements.services` (docs/14 §5.3): what it
 * restores, the deployment that re-places it and how far it got.
 */
final class ReplacedServiceData extends Data
{
    /**
     * @param  array{snapshot: string, takenAt: string|null}|null  $restore
     * @param  list<string>  $restoreRuns  public ids
     */
    public function __construct(
        public string $instance,
        public string $service,
        public string $name,
        public ReplacedServiceKind $kind,
        public ?array $restore,
        public array $restoreRuns,
        public ?string $deployment,
        public ReplacedServiceStatus $status,
        public ?string $message,
    ) {}

    /**
     * A copy with $changes applied (the stored rows are replaced, not edited).
     *
     * @param  array{status?: ReplacedServiceStatus, message?: string|null, deployment?: string}  $changes
     */
    public function changed(array $changes): self
    {
        return self::from([...$this->toArray(), ...$changes]);
    }
}
