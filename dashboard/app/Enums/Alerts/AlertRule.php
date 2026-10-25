<?php

declare(strict_types=1);

namespace App\Enums\Alerts;

/**
 * The built-in alert rules (docs/15 §3.4). Every rule is on by default;
 * teams switch rules off and tune thresholds in Settings → Notifications
 * (`alert_rule_settings`). Values are stored: never rename a case value.
 */
enum AlertRule: string
{
    case DeployFailed = 'deploy_failed';
    case ServiceUnhealthy = 'service_unhealthy';
    case ErrorRateHigh = 'error_rate_high';
    case ServerOffline = 'server_offline';
    case CpuHigh = 'cpu_high';
    case MemoryHigh = 'memory_high';
    case DiskFull = 'disk_full';
    case CertificateExpiring = 'certificate_expiring';
    case BackupFailed = 'backup_failed';
    /** A finding of a service instance became "Action needed" (docs/22 §10). */
    case Vulnerability = 'vulnerability';
    /** An edge serves its routes without the web firewall it was asked to run (docs/22 §6.6). */
    case WafUnavailable = 'waf_unavailable';

    /** Minutes a resolved alert can reopen without a new notification (flap guard). */
    public const int COOLDOWN_MINUTES = 10;

    public function label(): string
    {
        return match ($this) {
            self::DeployFailed => 'A deploy fails',
            self::ServiceUnhealthy => 'A service keeps crashing or fails its health check',
            self::ErrorRateHigh => 'Requests start failing',
            self::ServerOffline => 'A server goes offline',
            self::CpuHigh => 'CPU stays high',
            self::MemoryHigh => 'Memory stays high',
            self::DiskFull => 'A disk is nearly full',
            self::CertificateExpiring => 'A certificate is about to expire',
            self::BackupFailed => 'A backup fails',
            self::Vulnerability => 'A service needs a security update',
            self::WafUnavailable => 'The web firewall stops working on a server',
        };
    }

    /**
     * One line under the label. Thresholds are rendered by the UI as an
     * inline sentence, so these explain only what the label can't.
     */
    public function description(): string
    {
        return match ($this) {
            self::DeployFailed => 'Production and other persistent environments. Previews don’t send alerts.',
            self::ServiceUnhealthy => 'A replica is down, unhealthy or restarting again and again for 3 minutes.',
            self::ErrorRateHigh => 'Server errors (5xx) out of all requests, once there are at least 50.',
            self::ServerOffline => 'No contact for 5 minutes. You get another message when it is back.',
            self::CpuHigh => 'Average over the whole server.',
            self::MemoryHigh => 'Memory in use out of what the server has.',
            self::DiskFull => 'The disk Docker stores images, volumes and builds on.',
            self::CertificateExpiring => 'Renewal starts 30 days before expiry, so this alert means renewal keeps failing.',
            self::BackupFailed => 'Database, volume and launchroom backups.',
            self::Vulnerability => 'Only for findings marked Action needed on the Security page.',
            self::WafUnavailable => 'The server keeps serving requests without the firewall for 5 minutes.',
        };
    }

    /** Settings grouping: what the rule is about. */
    public function group(): string
    {
        return match ($this) {
            self::DeployFailed, self::ServiceUnhealthy, self::ErrorRateHigh => 'services',
            self::ServerOffline, self::CpuHigh, self::MemoryHigh, self::DiskFull => 'servers',
            self::CertificateExpiring, self::BackupFailed => 'data',
            self::Vulnerability, self::WafUnavailable => 'security',
        };
    }

    public function defaultSeverity(): AlertSeverity
    {
        return match ($this) {
            self::DeployFailed, self::ServiceUnhealthy, self::ServerOffline, self::BackupFailed => AlertSeverity::Critical,
            default => AlertSeverity::Warning,
        };
    }

    /**
     * The tunable threshold: bounds, step and unit ('%' or 'days'); null for
     * rules without one.
     *
     * @return array{min: float, max: float, step: float, unit: string, default: float}|null
     */
    public function threshold(): ?array
    {
        return match ($this) {
            self::ErrorRateHigh => ['min' => 1.0, 'max' => 100.0, 'step' => 1.0, 'unit' => '%', 'default' => 5.0],
            self::CpuHigh, self::MemoryHigh => ['min' => 10.0, 'max' => 100.0, 'step' => 1.0, 'unit' => '%', 'default' => 90.0],
            self::DiskFull => ['min' => 50.0, 'max' => 99.0, 'step' => 1.0, 'unit' => '%', 'default' => 90.0],
            self::CertificateExpiring => ['min' => 1.0, 'max' => 29.0, 'step' => 1.0, 'unit' => 'days', 'default' => 14.0],
            default => null,
        };
    }

    /**
     * How long (minutes) the condition must last; null for rules without one.
     *
     * @return array{min: int, max: int, default: int}|null
     */
    public function duration(): ?array
    {
        return match ($this) {
            self::ErrorRateHigh => ['min' => 1, 'max' => 30, 'default' => 5],
            self::CpuHigh, self::MemoryHigh => ['min' => 1, 'max' => 30, 'default' => 10],
            default => null,
        };
    }

    /**
     * Minutes after a notification before a new occurrence of a firing
     * alert notifies again; null: condition rules never repeat.
     */
    public function repeatAfterMinutes(): ?int
    {
        return match ($this) {
            self::DeployFailed => 10,
            self::BackupFailed => 360,
            // A second advisory for the same service notifies again (docs/22 §10).
            self::Vulnerability => 60,
            default => null,
        };
    }

    /**
     * Raised and resolved by the per-minute evaluator (the others come from
     * events or other workstreams).
     */
    public function isCondition(): bool
    {
        return in_array($this, [self::ServiceUnhealthy, self::ErrorRateHigh, self::CpuHigh, self::MemoryHigh, self::DiskFull, self::CertificateExpiring, self::WafUnavailable], true);
    }
}
