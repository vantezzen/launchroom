<?php

declare(strict_types=1);

namespace App\Support\Variables;

use App\Data\Protocol\V1\RequiredSecret;

/**
 * The ask when required secrets are missing (summary.requiredSecrets, see
 * CheckRequiredSecrets): "web needs RAILS_MASTER_KEY (the contents of
 * config/master.key). Add it as a secret variable, then deploy again."
 * Names and reasons only, never values.
 */
final class MissingSecretsMessage
{
    /**
     * @param  non-empty-list<RequiredSecret>  $missing
     */
    public static function for(string $service, array $missing): string
    {
        $parts = array_map(static fn (RequiredSecret $secret): string => $secret->reason === null || $secret->reason === ''
            ? $secret->name
            : "{$secret->name} ({$secret->reason})", $missing);
        $last = array_pop($parts);
        $list = $parts === [] ? $last : implode(', ', $parts).' and '.$last;

        return count($missing) === 1
            ? "{$service} needs {$list}. Add it as a secret variable, then deploy again."
            : "{$service} needs {$list}. Add them as secret variables, then deploy again.";
    }
}
