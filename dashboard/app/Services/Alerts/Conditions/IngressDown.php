<?php

declare(strict_types=1);

namespace App\Services\Alerts\Conditions;

use App\Data\Alerts\AlertSignal;
use App\Data\Ingress\IngressReportData;
use App\Enums\Alerts\AlertRule;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Support\Links;
use Carbon\CarbonImmutable;

/**
 * Online edges whose web server hasn't served their routes for DOWN_MINUTES
 * (Report.ingress state not `applied`, docs/05 §4): another program holds
 * port 80/443, Caddy refused the config, its image can't be pulled or it
 * keeps stopping. An offline edge is `server_offline`'s alert.
 */
final readonly class IngressDown extends AlertCondition
{
    public const int DOWN_MINUTES = 2;

    public function rule(): AlertRule
    {
        return AlertRule::IngressDown;
    }

    public function resolvedReason(): string
    {
        return 'It serves its routes again.';
    }

    public function failing(array $rules, array $firing, CarbonImmutable $now): array
    {
        $out = [];
        $edges = Server::query()->where('status', ServerStatus::Online->value)->withRole(ServerRole::Edge)->get();

        foreach ($edges as $server) {
            $status = $server->ingress_status;

            if (! $status instanceof IngressReportData || ! $status->downFor(self::DOWN_MINUTES, $now)) {
                continue;
            }

            $problem = $status->problem() ?? ['title' => 'Routes not applied', 'detail' => ''];
            $facts = array_filter([
                'Reason' => $problem['title'],
                'Port' => $status->port !== null ? (string) $status->port : null,
                'Program' => $status->process,
            ], static fn (?string $value): bool => $value !== null);

            $out[$server->team_id.'|server:'.$server->public_id] = fn (): AlertSignal => new AlertSignal(
                team: $this->team($server->team_id),
                rule: AlertRule::IngressDown,
                subject: 'server:'.$server->public_id,
                title: "{$server->name} isn’t serving your domains",
                summary: mb_substr(str_replace('Its last lines are below.', 'Its last lines are on the server page.', $problem['detail']), 0, 1000),
                href: Links::server($server, 'network'),
                server: $server,
                facts: $facts,
            );
        }

        return $out;
    }
}
