<?php

declare(strict_types=1);

namespace App\Services\Daemon;

use App\Models\InstanceSettings;
use App\Support\Crypto\OpaqueToken;
use Illuminate\Support\Facades\DB;

/**
 * The edge secret (docs/03 §4.3, protocol/SIGNING.md §6): the control-plane
 * edge adds it to every request it proxies to the dashboard (`X-Lr-Edge`),
 * so the dashboard can tell a verified client-certificate fingerprint set by
 * Caddy from a header anyone reaching its port could send. Generated once,
 * when client certificates are first switched on (`lr_edge_` + 256 bits),
 * stored encrypted with APP_KEY, delivered only sealed to the control-plane
 * server. Rendering only reads it.
 */
final class DaemonEdgeSecret
{
    public const string PREFIX = 'lr_edge_';

    public function current(): ?string
    {
        $secret = InstanceSettings::current()->daemon_edge_secret;

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    /**
     * The secret, created when there is none yet (never replaced).
     */
    public function provision(): string
    {
        return $this->current() ?? DB::transaction(static function (): string {
            $settings = InstanceSettings::lockedForUpdate();

            if (is_string($settings->daemon_edge_secret) && $settings->daemon_edge_secret !== '') {
                return $settings->daemon_edge_secret;
            }

            $secret = OpaqueToken::generate(self::PREFIX);
            $settings->forceFill(['daemon_edge_secret' => $secret])->save();

            return $secret;
        });
    }

    public function matches(string $presented): bool
    {
        $secret = InstanceSettings::current()->daemon_edge_secret;

        return is_string($secret) && $secret !== '' && hash_equals($secret, $presented);
    }
}
