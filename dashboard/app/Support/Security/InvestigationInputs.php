<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Data\Protocol\V1\ContainerInspectionResult;
use App\Enums\Security\WafCategory;
use Carbon\CarbonImmutable;

/**
 * Everything an investigation report is built from (docs/22 §7.4), read by
 * BuildInvestigationReport and turned into sentences by
 * InvestigationReport. Plain values only.
 */
final readonly class InvestigationInputs
{
    /**
     * @param  list<array{server: string, state: string, message: string|null, result: ContainerInspectionResult|null}>  $replicas
     * @param  list<array{kind: string, user: string, from: CarbonImmutable, to: CarbonImmutable|null}>  $sessions
     * @param  list<WafCategory>  $categories  the advisory's attack categories (empty: all)
     * @param  list<array{at: CarbonImmutable, action: string, categories: list<string>, network: string}>  $events  firewall events of the last 7 days
     * @param  array{blocked: int, detected: int}  $wafTotals  in the advisory's categories, last 7 days
     * @param  list<array{at: CarbonImmutable, requests: int, errors: int}>  $hours  requests per hour, last 7 days
     * @param  list<array{name: string, database: bool, engine: string|null}>  $connections
     * @param  list<string>  $secrets
     * @param  list<string>  $volumes
     * @param  list<string>  $domains
     * @param  array{name: string, version: string, ecosystem: string, fixed: string|null, groupKey: string, built: bool, lockfiles: list<string>, references: list<string>}|null  $finding
     * @param  array<string, string>  $hrefs  settings, variables, backups, deployments, security, repository, docs
     */
    public function __construct(
        public string $service,
        public CarbonImmutable $now,
        public array $replicas,
        public array $sessions,
        public string $wafMode,
        public array $categories,
        public array $events,
        public array $wafTotals,
        public array $hours,
        public ?CarbonImmutable $liveSince,
        public array $connections,
        public array $secrets,
        public array $volumes,
        public array $domains,
        public ?array $finding,
        public array $hrefs,
    ) {}
}
