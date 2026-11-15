<?php

declare(strict_types=1);

namespace App\Services\Alerts\Conditions;

use App\Data\Alerts\AlertSignal;
use App\Enums\Alerts\AlertRule;
use App\Enums\Security\WafEdgeState;
use App\Support\Links;
use Carbon\CarbonImmutable;

/**
 * Edges whose Caddy refused the firewall and serve their routes without
 * it (Report.ingress.waf.state `error`, docs/22 §6.6) for 5 minutes.
 */
final readonly class WafUnavailable extends AlertCondition
{
    public function rule(): AlertRule
    {
        return AlertRule::WafUnavailable;
    }

    public function resolvedReason(): string
    {
        return 'The web firewall runs again.';
    }

    public function failing(array $rules, array $firing, CarbonImmutable $now): array
    {
        $out = [];

        foreach ($this->activeServers() as $server) {
            $status = $server->waf_status;

            if ($status === null || $status->state !== WafEdgeState::Error || CarbonImmutable::parse($status->since)->greaterThan($now->subMinutes(5))) {
                continue;
            }

            $message = $status->message !== null ? mb_substr($status->message, 0, 300) : null;
            $out[$server->team_id.'|server:'.$server->public_id] = fn (): AlertSignal => new AlertSignal(
                team: $this->team($server->team_id),
                rule: AlertRule::WafUnavailable,
                subject: 'server:'.$server->public_id,
                title: "The web firewall is off on {$server->name}",
                summary: 'Its routes keep serving requests without the firewall until the server accepts the firewall settings again.'.($message === null ? '' : " The server said: {$message}"),
                href: Links::security(null, 'firewall'),
                server: $server,
            );
        }

        return $out;
    }
}
