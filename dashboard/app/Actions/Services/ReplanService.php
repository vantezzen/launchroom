<?php

declare(strict_types=1);

namespace App\Actions\Services;

use App\Enums\ServiceKind;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\PlanServiceJob;
use App\Models\ServiceInstance;

/**
 * Runs the plan preview again for the branch head (docs/04 §4).
 */
final readonly class ReplanService
{
    public function handle(ServiceInstance $instance): void
    {
        if ($instance->service->kind !== ServiceKind::App) {
            throw DomainRuleViolation::conflict('Only app services are planned.');
        }

        dispatch(new PlanServiceJob($instance->id));
    }
}
