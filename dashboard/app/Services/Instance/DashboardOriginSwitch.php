<?php

declare(strict_types=1);

namespace App\Services\Instance;

use App\Events\NodeStateInvalidated;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Support\Origin;

/**
 * Moves the canonical dashboard origin (docs/05 §4.1, D43). Used by
 * "Switch", "Switch back" and `launchroom:dashboard-domain:reset`; the
 * caller holds the instance settings row lock and audits.
 *
 * The origin it leaves stays in `dashboard_legacy_urls` with a fresh
 * transition window: Caddy keeps routing it, browsers are redirected, and
 * launchroomd keeps reaching the dashboard there (it is every server's
 * fallback URL). Loopback origins are never kept (nothing routes them).
 */
final readonly class DashboardOriginSwitch
{
    /** Earlier origins kept at most (newest first). */
    private const int MAX_LEGACY = 10;

    public function __construct(
        private DashboardOrigin $origin,
    ) {}

    /**
     * @return array{from: string, to: string, rpIdFrom: string|null, rpIdTo: string|null}
     */
    public function moveTo(InstanceSettings $settings, string $target, bool $forgetOld = false): array
    {
        $this->origin->refresh();
        $target = Origin::normalize($target) ?? $target;
        $from = $this->origin->canonical();
        $install = $this->origin->installUrl();
        $rpIdFrom = $this->origin->rpId();
        $now = now()->toImmutable();
        $until = $now->addDays(max(0, config()->integer('launchroom.dashboard.transition_days')));

        $legacy = [];

        foreach ($forgetOld ? [] : ($settings->dashboard_legacy_urls ?? []) as $entry) {
            $url = is_string($entry['url'] ?? null) ? Origin::normalize($entry['url']) : null;

            if (! in_array($url, [null, $target, $from], true)) {
                $legacy[] = [
                    'url' => $url,
                    'since' => is_string($entry['since'] ?? null) ? $entry['since'] : $now->toIso8601ZuluString(),
                    'until' => is_string($entry['until'] ?? null) ? $entry['until'] : $now->toIso8601ZuluString(),
                ];
            }
        }

        $fromHost = Origin::host($from);

        if (! $forgetOld && $from !== $target && $fromHost !== null && ! Origin::isLoopback($fromHost)) {
            array_unshift($legacy, ['url' => $from, 'since' => $now->toIso8601ZuluString(), 'until' => $until->toIso8601ZuluString()]);
        }

        $rpIdTo = $this->rpIdFor($target, $rpIdFrom);

        $settings->forceFill([
            'dashboard_url' => $target === $install ? null : $target,
            'dashboard_legacy_urls' => $legacy === [] ? null : array_slice($legacy, 0, self::MAX_LEGACY),
            'dashboard_pending_host' => null,
            'passkey_rp_id' => $rpIdTo,
            'dashboard_switched_at' => $now,
        ])->save();

        $this->origin->refresh();

        return ['from' => $from, 'to' => $target, 'rpIdFrom' => $rpIdFrom, 'rpIdTo' => $rpIdTo];
    }

    /**
     * The WebAuthn relying party id after moving to `target`: the current
     * one when the new host is it or below it (passkeys keep working, e.g.
     * example.com → dash.example.com), else the new host. IP origins have
     * none.
     */
    public function rpIdFor(string $target, ?string $current): ?string
    {
        $host = Origin::host($target);

        if ($host === null || Origin::isIp($host)) {
            return null;
        }

        if ($current !== null && ! Origin::isIp($current) && ($host === $current || str_ends_with($host, '.'.$current))) {
            return $current;
        }

        return $host;
    }

    /**
     * Every server re-renders: the control plane for its system routes,
     * the others for `system.controlPlane` and their forward-auth URLs.
     */
    public function invalidateServers(): void
    {
        $ids = Server::query()->whereNull('revoked_at')->pluck('id')->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)->all();

        if ($ids !== []) {
            event(new NodeStateInvalidated(array_values($ids)));
        }
    }

    public function invalidateControlPlane(): void
    {
        $ids = Server::query()->where('is_control_plane', true)->whereNull('revoked_at')->pluck('id')->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)->all();

        if ($ids !== []) {
            event(new NodeStateInvalidated(array_values($ids)));
        }
    }
}
