<?php

declare(strict_types=1);

namespace App\Data\Servers\Host;

use Spatie\LaravelData\Data;

/** How launchroomd's last reconcile passes went (Report.host.reconcile). */
final class ReconcileStatusData extends Data
{
    /**
     * @param  list<ReconcileErrorData>  $errors
     */
    public function __construct(
        public string $lastPassAt,
        public ?string $lastCompleteAt = null,
        public array $errors = [],
    ) {}

    public function has(string ...$codes): bool
    {
        return array_any($this->errors, fn (ReconcileErrorData $error): bool => in_array($error->code, $codes, true));
    }
}
