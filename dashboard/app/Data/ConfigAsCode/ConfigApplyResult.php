<?php

declare(strict_types=1);

namespace App\Data\ConfigAsCode;

/**
 * What applying a launchroom.json did (docs/18 §3.3): what it manages now,
 * what the trust rules ignored or clamped, and domain changes (applied
 * immediately, or only described in a dry run).
 */
final readonly class ConfigApplyResult
{
    /**
     * @param  list<ConfigFileProblemData>  $ignored
     * @param  list<array{service: string, hostname: string, kind: 'added'|'removed'}>  $domains
     */
    public function __construct(
        public ManagedConfigData $managed,
        public array $ignored = [],
        public array $domains = [],
    ) {}
}
