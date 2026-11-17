<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Alerts\OpenAlertData;
use App\Enums\Alerts\AlertKind;
use App\Enums\Alerts\AlertRule;
use App\Enums\Alerts\AlertSeverity;
use App\Enums\Alerts\AlertStatus;
use App\Enums\Backups\BackupAlertKind;
use App\Models\Alerts\Alert;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Firing alerts grouped by the thing they are about (docs/15 §3.6), for
 * the places that show them: canvas nodes and the service panel (by
 * service instance), server cards and the server page (by server), and
 * project cards (by project). Every read is constrained to one team; most
 * severe first, then newest.
 */
final class OpenAlerts
{
    /**
     * Firing alerts about the service instances of an environment, keyed by
     * service instance id.
     *
     * @return array<int, list<OpenAlertData>>
     */
    public function forEnvironment(int $teamId, int $environmentId): array
    {
        return $this->grouped(
            $this->firing($teamId)->where('environment_id', $environmentId)->whereNotNull('service_instance_id'),
            'service_instance_id',
        );
    }

    /**
     * Firing alerts about servers, keyed by server id.
     *
     * @param  list<int>  $serverIds
     * @return array<int, list<OpenAlertData>>
     */
    public function forServers(int $teamId, array $serverIds): array
    {
        if ($serverIds === []) {
            return [];
        }

        return $this->grouped(
            $this->firing($teamId)->whereIn('server_id', $serverIds)->whereNull('service_instance_id'),
            'server_id',
        );
    }

    /**
     * Firing alerts per project, keyed by project id.
     *
     * @param  list<int>  $projectIds
     * @return array<int, list<OpenAlertData>>
     */
    public function forProjects(int $teamId, array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }

        return $this->grouped($this->firing($teamId)->whereIn('project_id', $projectIds), 'project_id');
    }

    public static function data(Alert $alert): OpenAlertData
    {
        return new OpenAlertData(
            id: $alert->public_id,
            rule: $alert->rule,
            severity: $alert->severity,
            label: self::label($alert),
            title: $alert->title,
            href: $alert->href,
            openedAt: $alert->opened_at->toIso8601String(),
            kind: $alert->kind,
            backupKind: $alert->rule === AlertRule::BackupFailed ? BackupAlertKind::ofSubject($alert->subject_key) : null,
            value: $alert->value,
        );
    }

    /**
     * The chip's word or two: what is wrong, with the number when there is
     * one ("Errors 8%", "Disk 93%").
     */
    public static function label(Alert $alert): string
    {
        return match ($alert->rule) {
            AlertRule::DeployFailed => 'Deploy failed',
            AlertRule::ServiceUnhealthy => ($alert->kind ?? AlertKind::Unhealthy)->label(),
            AlertRule::ErrorRateHigh => self::withNumber('Errors', $alert->value, trim: true),
            AlertRule::ServerOffline => 'Offline',
            AlertRule::CpuHigh => self::withNumber('CPU', $alert->value),
            AlertRule::MemoryHigh => self::withNumber('Memory', $alert->value),
            AlertRule::DiskFull => self::withNumber('Disk', $alert->value),
            AlertRule::CertificateExpiring => 'Certificate expiring',
            AlertRule::BackupFailed => (BackupAlertKind::ofSubject($alert->subject_key) ?? BackupAlertKind::Failed)->label(),
            AlertRule::Vulnerability => 'Vulnerability',
            AlertRule::WafUnavailable => 'Firewall off',
            AlertRule::IngressDown => 'Not serving',
            AlertRule::CertificateFailed => 'Certificate failed',
            AlertRule::ServiceCrashed => 'Crashed',
            AlertRule::CronFailed => 'Cron failed',
            AlertRule::ControlPlaneDegraded => 'launchroom degraded',
            AlertRule::QueueBacklog => 'Jobs waiting',
        };
    }

    /**
     * @return Builder<Alert>
     */
    private function firing(int $teamId): Builder
    {
        return Alert::query()
            ->where('team_id', $teamId)
            ->where('status', AlertStatus::Firing->value);
    }

    /**
     * @param  Builder<Alert>  $query
     * @return array<int, list<OpenAlertData>>
     */
    private function grouped(Builder $query, string $key): array
    {
        /** @var Collection<int, Alert> $alerts */
        $alerts = $query->latest('opened_at')->limit(500)->get();
        $sorted = $alerts->sortBy(static fn (Alert $alert): int => $alert->severity === AlertSeverity::Critical ? 0 : 1)->values();
        $out = [];

        foreach ($sorted as $alert) {
            $id = $alert->getAttribute($key);

            if (is_int($id)) {
                $out[$id][] = self::data($alert);
            }
        }

        return $out;
    }

    /** "Disk 93%", "Errors 8.5%"; the word alone without a number. */
    private static function withNumber(string $word, ?float $percent, bool $trim = false): string
    {
        if ($percent === null) {
            return $word;
        }

        if ($percent >= 10) {
            return "{$word} ".round($percent).'%';
        }

        $number = number_format($percent, 1);

        return "{$word} ".($trim ? rtrim(rtrim($number, '0'), '.') : $number).'%';
    }
}
