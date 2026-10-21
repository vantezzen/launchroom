<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Enums\Alerts\AlertDeliveryEvent;
use App\Models\Alerts\Alert;
use App\Models\Team;
use Carbon\CarbonImmutable;

/**
 * Builds {@see AlertMessage}s from alerts (and the test message). Relations
 * must be loaded by the caller (models are strict).
 */
final readonly class AlertMessages
{
    public function forAlert(Alert $alert, AlertDeliveryEvent $event, Team $team): AlertMessage
    {
        $summary = $event === AlertDeliveryEvent::Resolved
            ? ($alert->resolve_reason ?? 'It is back to normal.')
            : $alert->summary;
        $url = $this->absolute($alert->href ?? '/activity');
        $service = $alert->serviceInstance?->service;
        $context = array_values(array_filter([
            $alert->project?->name,
            $alert->environment?->name,
            $service?->name,
            $alert->server?->name,
        ], static fn (?string $part): bool => $part !== null && $part !== ''));

        return new AlertMessage(
            event: $event,
            title: $alert->title,
            summary: $summary,
            severity: $alert->severity,
            url: $url,
            facts: $alert->facts,
            context: $context,
            payload: [
                'type' => $event->type(),
                'id' => $alert->public_id,
                'rule' => $alert->rule->value,
                'severity' => $alert->severity->value,
                'status' => $alert->status->value,
                'title' => $alert->title,
                'summary' => $summary,
                'url' => $url,
                'team' => ['id' => $team->public_id, 'name' => $team->name],
                'project' => $alert->project === null ? null : ['id' => $alert->project->public_id, 'name' => $alert->project->name],
                'environment' => $alert->environment === null ? null : ['id' => $alert->environment->public_id, 'name' => $alert->environment->name],
                'service' => $service === null ? null : ['id' => $service->public_id, 'name' => $service->name],
                'server' => $alert->server === null ? null : ['id' => $alert->server->public_id, 'name' => $alert->server->name],
                'facts' => (object) $alert->facts,
                'openedAt' => $alert->opened_at->toIso8601ZuluString(),
                'resolvedAt' => $alert->resolved_at?->toIso8601ZuluString(),
                'sentAt' => CarbonImmutable::now()->toIso8601ZuluString(),
            ],
        );
    }

    public function test(Team $team): AlertMessage
    {
        $title = 'launchroom can reach you here';
        $summary = 'This is a test from Settings → Notifications. Alerts will look like this.';
        $url = $this->absolute('/settings/notifications');

        return new AlertMessage(
            event: AlertDeliveryEvent::Test,
            title: $title,
            summary: $summary,
            severity: null,
            url: $url,
            facts: [],
            context: [$team->name],
            payload: [
                'type' => AlertDeliveryEvent::Test->type(),
                'id' => null,
                'rule' => null,
                'severity' => null,
                'status' => null,
                'title' => $title,
                'summary' => $summary,
                'url' => $url,
                'team' => ['id' => $team->public_id, 'name' => $team->name],
                'project' => null,
                'environment' => null,
                'service' => null,
                'server' => null,
                'facts' => (object) [],
                'openedAt' => null,
                'resolvedAt' => null,
                'sentAt' => CarbonImmutable::now()->toIso8601ZuluString(),
            ],
        );
    }

    private function absolute(string $href): string
    {
        if (preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }

        return rtrim(config()->string('app.url'), '/').'/'.ltrim($href, '/');
    }
}
