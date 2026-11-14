<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Alerts\OpenAlertData;
use App\Enums\Alerts\AlertRule;
use App\Enums\Alerts\AlertSeverity;
use App\Enums\Alerts\AlertStatus;
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
        );
    }

    /**
     * The chip's word or two: what is wrong, with the number when there is
     * one ("Errors 8%", "Disk 93%").
     */
    public static function label(Alert $alert): string
    {
        $facts = $alert->facts;
        $kind = explode(':', $alert->subject_key, 2)[0];

        return match ($alert->rule) {
            AlertRule::DeployFailed => 'Deploy failed',
            AlertRule::ServiceUnhealthy => str_contains($alert->title, 'restarting') ? 'Restarting' : 'Unhealthy',
            AlertRule::ErrorRateHigh => self::errorLabel($facts['Errors'] ?? null),
            AlertRule::ServerOffline => 'Offline',
            AlertRule::CpuHigh => self::withNumber('CPU', $facts['CPU'] ?? null),
            AlertRule::MemoryHigh => self::withNumber('Memory', $facts['Memory'] ?? null),
            AlertRule::DiskFull => self::withNumber('Disk', $facts['Disk'] ?? null),
            AlertRule::CertificateExpiring => 'Certificate expiring',
            AlertRule::BackupFailed => match ($kind) {
                'backup_overdue' => 'Backup overdue',
                'backup_verify' => 'Backup check failed',
                'wal_archive' => 'Archiving behind',
                default => 'Backup failed',
            },
            AlertRule::Vulnerability => 'Vulnerability',
            AlertRule::WafUnavailable => 'Firewall off',
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

    /** "Errors 8%" from the fact "12 of 140 requests". */
    private static function errorLabel(?string $fact): string
    {
        if ($fact !== null && preg_match('/^([\d,]+) of ([\d,]+)/', $fact, $m) === 1) {
            $errors = (int) str_replace(',', '', $m[1]);
            $requests = (int) str_replace(',', '', $m[2]);

            if ($requests > 0) {
                $rate = 100 * $errors / $requests;

                return 'Errors '.($rate >= 10 ? (string) round($rate) : rtrim(rtrim(number_format($rate, 1), '0'), '.')).'%';
            }
        }

        return 'Errors';
    }

    /** "Disk 93%" from "93% full"; the word alone without a number. */
    private static function withNumber(string $word, ?string $fact): string
    {
        return $fact !== null && preg_match('/^(\d+(?:\.\d)?%)/', $fact, $m) === 1 ? "{$word} {$m[1]}" : $word;
    }
}
