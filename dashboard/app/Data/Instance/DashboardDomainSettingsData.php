<?php

declare(strict_types=1);

namespace App\Data\Instance;

use App\Data\Canvas\DnsRecordData;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * Settings → Dashboard domain (docs/05 §4.1, docs/08 §17), instance owners.
 */
final class DashboardDomainSettingsData extends Data
{
    /**
     * @param  list<DnsRecordData>  $records  A/AAAA to create (the name filled in once a domain is entered)
     * @param  list<DashboardLegacyOriginData>  $legacy
     * @param  list<DashboardServerUrlData>  $servers  every server but the main one
     * @param  list<DashboardGitHubAppData>  $githubApps
     * @param  array{webhook: string, callback: string, setup: string, homepage: string}  $githubUrls  the URLs to set on each app
     */
    public function __construct(
        public DashboardOriginData $current,
        /** The main server's Caddy routes the dashboard (production install). */
        public bool $routable,
        public array $records,
        public ?DashboardDomainChangeData $change,
        /** The domain set up here that is active (Switch back target: its previousUrl). */
        public ?string $activeHostname,
        public ?string $switchBackTo,
        public ?CarbonInterface $activeSince,
        public array $legacy,
        public array $servers,
        /** The mesh's coordination URL when the built-in mesh is on (it doesn't move). */
        public ?string $meshLoginUrl,
        public array $githubApps,
        public array $githubUrls,
        public int $transitionDays,
        public string $resetCommand,
    ) {}
}
