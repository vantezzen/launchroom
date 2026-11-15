<?php

declare(strict_types=1);

namespace App\Data\Servers;

use Spatie\LaravelData\Data;

/**
 * `servers.daemon_control_plane`: which dashboard URL launchroomd uses
 * (Report.host.controlPlane, docs/05 §4.1) and when it said so.
 */
final class ControlPlaneUrlReportData extends Data
{
    public function __construct(
        /** `configured`, `adopted`, `fallback`, `pending` or `refused`. */
        public string $state,
        public string $reportedAt,
        public ?string $configured = null,
        public ?string $adopted = null,
        public ?string $active = null,
        public ?string $message = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $reported  validated by App\Data\Protocol\V1\HostStatus::rules()
     */
    public static function fromProtocol(array $reported, string $now): self
    {
        $string = static fn (string $key): ?string => is_string($reported[$key] ?? null) ? $reported[$key] : null;

        return new self(
            state: $string('state') ?? 'configured',
            reportedAt: $now,
            configured: $string('configured'),
            adopted: $string('adopted'),
            active: $string('active'),
            message: is_string($reported['message'] ?? null) ? mb_substr($reported['message'], 0, 512) : null,
        );
    }
}
