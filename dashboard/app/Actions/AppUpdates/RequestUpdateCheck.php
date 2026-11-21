<?php

declare(strict_types=1);

namespace App\Actions\AppUpdates;

use App\Exceptions\DomainRuleViolation;
use App\Jobs\AppUpdates\CheckAppUpdatesJob;
use App\Models\ServiceInstance;
use App\Services\AppUpdates\UpdatePlan;
use Illuminate\Support\Facades\Cache;

/**
 * **Check now** (docs/04 §14.1): queues the version check of one service
 * right away, at most once per 5 minutes per service instance.
 */
final readonly class RequestUpdateCheck
{
    public const int THROTTLE_SECONDS = 300;

    public function handle(ServiceInstance $instance): void
    {
        $instance->loadMissing(['service', 'environment']);

        if (! UpdatePlan::isChecked($instance)) {
            throw DomainRuleViolation::on('service', 'launchroom checks running image services of lasting environments.');
        }

        if (! Cache::add('app-updates:check-now:'.$instance->id, true, self::THROTTLE_SECONDS)) {
            throw DomainRuleViolation::on('service', 'Checked a moment ago. Try again in a few minutes.');
        }

        dispatch(new CheckAppUpdatesJob($instance->id));
    }
}
