<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Data\Audit\AuditActor;
use App\Data\Security\AddWafExclusionData;
use App\Exceptions\DomainRuleViolation;
use App\Models\Security\WafExclusion;
use App\Models\ServiceInstance;
use App\Models\User;

/**
 * Adds one firewall exception by hand (docs/22 §6.4), refusing one that
 * already exists; the rest (limits, audit, re-render) is AddWafExclusions.
 */
final readonly class AddWafExclusion
{
    public function __construct(
        private AddWafExclusions $add,
    ) {}

    public function handle(ServiceInstance $instance, AddWafExclusionData $data, User $user, AuditActor $actor): WafExclusion
    {
        $added = $this->add->handle($instance, [$data], $user, $actor);

        if ($added === []) {
            throw DomainRuleViolation::on('rule', 'This exception already exists.');
        }

        return $added[0];
    }
}
