<?php

declare(strict_types=1);

namespace App\Actions\Variables;

use App\Events\CanvasChanged;
use App\Exceptions\DomainRuleViolation;
use App\Models\Service;
use App\Models\Variable;
use App\Services\Variables\VariableStore;

/**
 * Opts a service in or out of a shared variable ("Use in: web, api",
 * docs/06 §3.1). Staged. Opting in can't make a browser-inlined variable
 * carry a secret (VariableStore::write).
 */
final readonly class SetSharedVariableUsage
{
    public function __construct(
        private VariableStore $store,
    ) {}

    public function handle(Variable $variable, Service $service, bool $use): void
    {
        if ($variable->service_id !== null || $variable->project_id !== $service->project_id) {
            throw DomainRuleViolation::on('variable', 'Only shared variables of the same project can be used by a service.');
        }

        $this->store->write($variable->project, function () use ($variable, $service, $use): void {
            $use ? $variable->usedBy()->syncWithoutDetaching([$service->id]) : $variable->usedBy()->detach($service->id);

            if ($use) {
                $this->store->touch($variable->key);
            }

            CanvasChanged::forService($service);
        }, 'variable');
    }
}
