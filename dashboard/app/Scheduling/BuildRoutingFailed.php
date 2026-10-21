<?php

declare(strict_types=1);

namespace App\Scheduling;

use DomainException;

/**
 * No build server can build for the platforms the service runs on; the
 * message is user-facing.
 */
final class BuildRoutingFailed extends DomainException
{
    /**
     * @param  list<string>  $platforms
     */
    public static function noBuilderFor(array $platforms): self
    {
        $list = implode(' or ', $platforms);

        return new self("No online build server can build for {$list}, the platform of the servers this service may run on. Add a build server with that architecture, or restrict the service to servers of a platform you can build for (Settings → Scaling).");
    }
}
