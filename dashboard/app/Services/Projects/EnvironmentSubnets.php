<?php

declare(strict_types=1);

namespace App\Services\Projects;

use App\Exceptions\DomainRuleViolation;
use App\Models\Environment;
use App\Support\Network\SubnetAllocator;
use Illuminate\Support\Facades\DB;

/**
 * Allocates an environment's /24 from launchroom's range (docs/05 §1),
 * unique per install. Serialized by a transaction-scoped advisory lock (the
 * unique index on environments.network_subnet is the backstop). Must run
 * inside the caller's transaction.
 */
final class EnvironmentSubnets
{
    /** Advisory lock key ("launchroom subnets"). */
    private const int LOCK_KEY = 0x6C72_7375_626E_6574;

    public function allocate(): string
    {
        DB::select('SELECT pg_advisory_xact_lock(?)', [self::LOCK_KEY]);

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
