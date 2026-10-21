<?php

declare(strict_types=1);

namespace App\Data\Api;

use Spatie\LaravelData\Data;

/**
 * The resolved env of a service (`env pull`, `run`; docs/16 §3.3).
 * `withheld` lists secret (or secret-tainted) keys left out because the
 * token or the role may not read secrets, or `secrets=1` wasn't asked.
 */
final class ResolvedEnvData extends Data
{
    /**
     * @param  array<string, string>  $variables
     * @param  list<string>  $withheld
     */
    public function __construct(
        public array $variables,
        public array $withheld,
        public ?int $revision,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['variables' => array_fill_keys(array_keys($this->variables), '[redacted]'), 'withheld' => $this->withheld];
    }
}
