<?php

declare(strict_types=1);

namespace App\Actions\Environments;

use App\Enums\AdvisoryLock;
use App\Exceptions\DomainRuleViolation;
use App\Models\Environment;
use App\Support\Network\SubnetAllocator;

/**
 * Allocates an environment's /24 from launchroom's range (docs/05 §1),
 * unique per install. Serialized by a transaction-scoped advisory lock (the
 * unique index on environments.network_subnet is the backstop). Must run
 * inside the caller's transaction.
 */
final readonly class AllocateEnvironmentSubnet
{
    public function handle(): string
    {
        AdvisoryLock::EnvironmentSubnets->holdForTransaction();

        /** @var list<string> $used */
        $used = Environment::query()->pluck('network_subnet')->all();

        $subnet = SubnetAllocator::next(
            config()->string('launchroom.networks.environment_range'),
            config()->integer('launchroom.networks.environment_prefix'),
            $used,
        );

        if ($subnet === null) {
            throw DomainRuleViolation::conflict('No free network range is left for a new environment. Delete unused environments or widen LAUNCHROOM_ENVIRONMENT_RANGE.');
        }

        return $subnet;
    }
}
