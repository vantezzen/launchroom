<?php

declare(strict_types=1);

namespace App\Services\Instance;

use App\Models\InstanceSettings;
use App\Models\Server;
use App\Support\Origin;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Where the dashboard lives (docs/05 §4.1, D43): the source of truth for its
 * canonical origin. Settings → Dashboard domain stores it in
 * `instance_settings.dashboard_url`; without that it is `APP_URL`, the
 * install-time origin. Earlier origins (`dashboard_legacy_urls`) keep
 * answering: browsers are redirected, launchroomd's endpoints are served
 * (UseDashboardOrigin).
 *
 * `apply()` runs at the start of every request (UseDashboardOrigin), queued
 * job and launchroom console command, so everything that reads `app.url`
 * (links in mails, `route()` outside requests, the passkey relying party,
 * the installer script, the recovery kit) follows the switch without
 * editing `.env` at runtime.
 *
 * Scoped: the settings row is read once per request or job.
 */
final class DashboardOrigin
{
    private bool $loaded = false;

    private ?InstanceSettings $settings = null;

    /**
     * The canonical origin (`scheme://host[:port]`, no trailing slash).
     */
    public function canonical(): string
    {
        return $this->override() ?? $this->bootUrl();
    }

    public function canonicalHost(): ?string
    {
        return Origin::host($this->canonical());
    }

    /**
     * Whether the canonical origin was chosen in Settings (not APP_URL).
     */
    public function isSwitched(): bool
    {
        return $this->override() !== null;
    }

    /**
     * The install-time origin (`APP_URL`). The mesh coordination URL stays
     * here: it is baked into every server's Tailscale client.
     */
    public function installUrl(): string
    {
        return $this->bootUrl();
    }

    /**
     * Earlier origins that still answer, newest first, without the canonical one.
     *
     * @return list<array{url: string, host: string, since: CarbonImmutable, until: CarbonImmutable}>
     */
    public function legacy(): array
    {
        $entries = $this->settings()?->dashboard_legacy_urls;
        $canonical = $this->canonical();
        $legacy = [];

        foreach (is_array($entries) ? $entries : [] as $entry) {
            $url = is_string($entry['url'] ?? null) ? Origin::normalize($entry['url']) : null;
            $host = $url === null ? null : Origin::host($url);

            if ($url === null || $host === null || $url === $canonical) {
                continue;
            }

            try {
                $since = CarbonImmutable::parse(is_string($entry['since'] ?? null) ? $entry['since'] : 'now');
                $until = CarbonImmutable::parse(is_string($entry['until'] ?? null) ? $entry['until'] : 'now');
            } catch (Throwable) {
                continue;
            }

            $legacy[] = ['url' => $url, 'host' => $host, 'since' => $since, 'until' => $until];
        }

        return $legacy;
    }

    /**
     * Every origin the dashboard answers launchroomd on: canonical, install-time, earlier ones.
     *
     * @return list<string>
     */
    public function knownOrigins(): array
    {
        return array_values(array_unique([
            $this->canonical(),
            $this->installUrl(),
            ...array_map(static fn (array $legacy): string => $legacy['url'], $this->legacy()),
        ]));
    }

    /**
     * The origin a server's launchroomd talks to (Report.host.controlPlane):
     * the canonical one it adopted, else the URL it was installed with,
     * when either is one of ours; else the install-time origin, which is
     * what servers without the `controlPlaneUrl` feature were installed
     * with. launchroomd only calls forward-auth URLs on these origins
     * (docs/03 §8), so preview protection follows it.
     */
    public function forServer(Server $server): string
    {
        $reported = $server->daemon_control_plane ?? [];
        $known = $this->knownOrigins();

        foreach (['adopted', 'configured'] as $field) {
            $url = is_string($reported[$field] ?? null) ? Origin::normalize($reported[$field]) : null;

            if ($url !== null && in_array($url, $known, true)) {
                return $url;
            }
        }

        return $this->installUrl();
    }

    /**
     * A new domain the control-plane Caddy routes while it is verified.
     */
    public function pendingHost(): ?string
    {
        return $this->settings()?->dashboard_pending_host;
    }

    /**
     * The WebAuthn relying party id: the one chosen at the last switch, else
     * the canonical host. IP origins have none (browsers refuse them).
     */
    public function rpId(): ?string
    {
        $stored = $this->settings()?->passkey_rp_id;

        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        $host = $this->canonicalHost();

        return $host === null || Origin::isIp($host) ? null : $host;
    }

    /**
     * Points the framework at the canonical origin for this request, job or
     * command.
     */
    public function apply(bool $console = false): void
    {
        $this->refresh();

        // APP_URL as configured, before any switch was applied: kept across
        // the jobs of a long-running worker, re-read when something other
        // than apply() changed app.url since.
        $current = config()->string('app.url');

        if (! is_string(config('launchroom.dashboard.boot_url')) || $current !== config('launchroom.dashboard.applied_url')) {
            config(['launchroom.dashboard.boot_url' => $current]);
        }

        $canonical = $this->canonical();
        // Not switched: APP_URL exactly as configured (only a switch rewrites it).
        $url = $this->isSwitched() ? $canonical : config()->string('launchroom.dashboard.boot_url');
        config(['app.url' => $url, 'launchroom.dashboard.applied_url' => $url]);

        $rpId = $this->rpId();

        if ($this->isSwitched() || $this->settings()?->passkey_rp_id !== null) {
            config([
                'passkeys.relying_party_id' => $rpId ?? Origin::host($canonical),
                'passkeys.allowed_origins' => [$canonical],
            ]);
        }

        if (Origin::isHttps($canonical)) {
            // Only over https, host-only and never readable by an app on a
            // sibling domain (cookie tossing): `__Host-` (docs/09 R26).
            config(['session.secure' => true]);
            $cookie = config()->string('session.cookie');

            if (config('session.domain') === null && ! str_starts_with($cookie, '__Host-')) {
                config(['session.cookie' => '__Host-'.$cookie]);
            }
        }

        if ($console) {
            URL::useOrigin($canonical);
        }
    }

    /**
     * Forgets the loaded settings (after a change in the same request).
     */
    public function refresh(): void
    {
        $this->loaded = false;
        $this->settings = null;
    }

    private function override(): ?string
    {
        $url = $this->settings()?->dashboard_url;

        return is_string($url) && $url !== '' ? Origin::normalize($url) : null;
    }

    private function settings(): ?InstanceSettings
    {
        if (! $this->loaded) {
            $this->loaded = true;

            try {
                $this->settings = InstanceSettings::query()->find(InstanceSettings::ID);
            } catch (Throwable) {
                // Before the first migration or without a database (image
                // builds): APP_URL.
                $this->settings = null;
            }
        }

        return $this->settings;
    }

    /**
     * APP_URL: as captured by the first apply() of this process, else as configured.
     */
    private function bootUrl(): string
    {
        $boot = config('launchroom.dashboard.boot_url');

        return $this->trimmed(is_string($boot) ? $boot : config()->string('app.url'));
    }

    private function trimmed(string $url): string
    {
        return Origin::normalize($url) ?? rtrim($url, '/');
    }
}
