<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Canvas\DnsRecordData;
use App\Data\Domains\DnsCheckData;
use App\Data\Instance\DashboardCertificateData;
use App\Data\Instance\DashboardDomainChangeData;
use App\Data\Instance\DashboardDomainSettingsData;
use App\Data\Instance\DashboardDomainStepData;
use App\Data\Instance\DashboardGitHubAppData;
use App\Data\Instance\DashboardLegacyOriginData;
use App\Data\Instance\DashboardOriginData;
use App\Data\Instance\DashboardProbeData;
use App\Data\Instance\DashboardServerUrlData;
use App\Data\Instance\DashboardSwitchImpactData;
use App\Enums\Mesh\MeshMode;
use App\Models\DashboardDomainChange;
use App\Models\Git\GitHubApp;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Instance\DashboardDomainChecks;
use App\Services\Instance\DashboardOrigin;
use App\Services\Instance\DashboardOriginSwitch;
use App\Services\Mesh\MeshTopology;
use App\Support\Origin;
use Illuminate\Contracts\Database\Query\Builder;
use Laravel\Passkeys\Passkey;

/**
 * Settings → Dashboard domain (docs/05 §4.1): where the dashboard runs, the
 * domain being set up with its steps, what a switch changes (passkeys,
 * GitHub Apps), the earlier addresses that still answer and which address
 * every server's launchroomd uses.
 */
final readonly class DashboardDomainSettings
{
    public const string RESET_COMMAND = './launchroom dashboard-domain-reset';

    public function __construct(
        private DashboardOrigin $origin,
        private DashboardDomainChecks $checks,
        private DashboardOriginSwitch $switch,
        private MeshTopology $mesh,
    ) {}

    public function get(User $user): DashboardDomainSettingsData
    {
        $this->origin->refresh();
        $settings = InstanceSettings::current();
        $canonical = $this->origin->canonical();
        $host = Origin::host($canonical) ?? $canonical;
        $open = DashboardDomainChange::open();
        $active = DashboardDomainChange::active();
        $name = $open?->hostname;

        return new DashboardDomainSettingsData(
            current: new DashboardOriginData(
                url: $canonical,
                host: $host,
                https: Origin::isHttps($canonical),
                ip: Origin::isIp($host),
                switched: $this->origin->isSwitched(),
                installUrl: $this->origin->installUrl(),
                switchedAt: $settings->dashboard_switched_at,
            ),
            routable: $this->checks->routable(),
            records: array_map(static fn (array $record): DnsRecordData => new DnsRecordData($record[0], $name ?? '', $record[1]), $this->checks->records()),
            change: $open instanceof DashboardDomainChange ? $this->change($open, $user) : null,
            activeHostname: $active?->hostname,
            switchBackTo: $active instanceof DashboardDomainChange ? Origin::normalize($active->previous_url) : null,
            activeSince: $active?->switched_at,
            legacy: $this->legacy(),
            servers: $this->servers($canonical),
            meshLoginUrl: $this->mesh->mode($settings) === MeshMode::Embedded ? MeshTopology::embeddedLoginServer() : null,
            githubApps: $this->githubApps($user),
            githubUrls: [
                'homepage' => $canonical,
                'webhook' => $canonical.'/webhooks/github',
                'callback' => $canonical.route('git.github.manifest.callback', absolute: false),
                'setup' => $canonical.route('git.github.setup', absolute: false),
            ],
            transitionDays: config()->integer('launchroom.dashboard.transition_days'),
            resetCommand: self::RESET_COMMAND,
        );
    }

    private function change(DashboardDomainChange $change, User $user): DashboardDomainChangeData
    {
        $dns = DnsCheckData::fromStored($change->dns);
        $probe = DashboardProbeData::fromStored($change->probe);
        $certificate = DashboardCertificateData::fromStored($change->certificate);
        $dnsOk = $dns?->status === 'ok';
        $httpsOk = $probe?->ok === true;

        $dnsState = match (true) {
            ! $dns instanceof DnsCheckData => 'waiting',
            $dnsOk => 'done',
            $dns->status === 'mismatch' => 'failed',
            default => 'waiting',
        };

        $certificateState = match (true) {
            ! $dnsOk => 'blocked',
            $httpsOk || $certificate?->state === 'issued' => 'done',
            $certificate?->state === 'failed' => 'failed',
            default => 'waiting',
        };

        $httpsState = match (true) {
            $httpsOk => 'done',
            ! $dnsOk || $certificateState === 'failed' => 'blocked',
            $certificateState === 'done' && $probe instanceof DashboardProbeData => 'failed',
            default => 'waiting',
        };

        return new DashboardDomainChangeData(
            hostname: $change->hostname,
            url: 'https://'.$change->hostname,
            status: $change->status->value,
            steps: [
                new DashboardDomainStepData('dns', $dnsState, $dns?->message),
                new DashboardDomainStepData('certificate', $certificateState, $certificate?->lastError),
                new DashboardDomainStepData('https', $httpsState, $dnsOk ? $probe?->message : null),
            ],
            dns: $dns,
            probe: $dnsOk ? $probe : null,
            certificate: $certificate,
            impact: $this->impact('https://'.$change->hostname, $user),
            checkedAt: $change->checked_at,
            readyAt: $change->ready_at,
            createdAt: $change->created_at,
        );
    }

    private function impact(string $target, User $user): DashboardSwitchImpactData
    {
        $current = $this->origin->rpId();
        $next = $this->switch->rpIdFor($target, $current);
        $affected = static fn () => Passkey::query()->where(static fn (Builder $query) => $next === null
            ? $query->whereRaw('true')
            : $query->whereNull('rp_id')->orWhere('rp_id', '!=', $next));

        return new DashboardSwitchImpactData(
            rpId: $next,
            keepsPasskeys: $next !== null && $next === $current,
            passkeysAffected: $affected()->count(),
            yourPasskeysAffected: $affected()->where('user_id', $user->id)->count(),
        );
    }

    /**
     * @return list<DashboardLegacyOriginData>
     */
    private function legacy(): array
    {
        $install = $this->origin->installUrl();

        return array_map(static fn (array $legacy): DashboardLegacyOriginData => new DashboardLegacyOriginData(
            url: $legacy['url'],
            host: $legacy['host'],
            since: $legacy['since'],
            until: $legacy['until'],
            inTransition: $legacy['until']->isFuture(),
            isInstallUrl: $legacy['url'] === $install,
        ), $this->origin->legacy());
    }

    /**
     * @return list<DashboardServerUrlData>
     */
    private function servers(string $canonical): array
    {
        return array_values(Server::query()
            ->where('is_control_plane', false)
            ->whereNull('revoked_at')
            ->orderBy('name')
            ->get()
            ->map(static function (Server $server) use ($canonical): DashboardServerUrlData {
                $reported = $server->daemon_control_plane;
                $active = is_string($reported['active'] ?? null) ? $reported['active'] : null;

                return new DashboardServerUrlData(
                    id: $server->public_id,
                    name: $server->name,
                    state: is_array($reported) ? (string) $reported['state'] : 'unknown',
                    url: $active,
                    message: is_string($reported['message'] ?? null) ? $reported['message'] : null,
                    current: $active !== null && Origin::normalize($active) === $canonical,
                );
            })
            ->all());
    }

    /**
     * @return list<DashboardGitHubAppData>
     */
    private function githubApps(User $user): array
    {
        $apps = GitHubApp::query()->whereIn('team_id', $user->teamIdsQuery())->orderBy('name')->get();
        $teams = Team::query()->whereIn('id', $apps->pluck('team_id'))->pluck('name', 'id');

        return array_values($apps->map(static fn (GitHubApp $app): DashboardGitHubAppData => new DashboardGitHubAppData(
            name: $app->name,
            team: is_string($teams[$app->getAttribute('team_id')] ?? null) ? $teams[$app->getAttribute('team_id')] : '',
            appUrl: $app->html_url,
            hasWebhook: $app->webhook_secret !== null,
        ))->all());
    }
}
