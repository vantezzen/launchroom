<?php

declare(strict_types=1);

namespace App\Data\Services;

use App\Data\Protocol\V1\BuildSummary;
use App\Models\ServiceInstance;
use Spatie\LaravelData\Data;
use Throwable;

/**
 * The latest plan preview of a service instance ("we detected…", docs/04 §4),
 * from `service_instances.plan`: `status` is `planning` (a plan task is
 * queued or running), `ready` (summary available) or `failed` (`error`, and
 * `summary` when the daemon got far enough to detect something).
 */
final class ServicePlanData extends Data
{
    public function __construct(
        public string $status,
        public ?string $commitSha,
        public ?string $branch,
        public ?string $requestedAt,
        public ?string $finishedAt,
        public ?BuildSummary $summary,
        public ?string $error,
        public ?string $failureClass,
    ) {}

    public static function forInstance(ServiceInstance $instance): ?self
    {
        $plan = $instance->plan;

        if (! is_array($plan) || ! is_string($plan['status'] ?? null)) {
            return null;
        }

        $summary = null;

        if (is_array($plan['summary'] ?? null)) {
            try {
                $summary = BuildSummary::from($plan['summary']);
            } catch (Throwable) {
                $summary = null;
            }
        }

        return new self(
            status: $plan['status'],
            commitSha: self::string($plan, 'sha'),
            branch: self::string($plan, 'branch'),
            requestedAt: self::string($plan, 'requestedAt'),
            finishedAt: self::string($plan, 'finishedAt'),
            summary: $summary,
            error: self::string($plan, 'error'),
            failureClass: self::string($plan, 'failureClass'),
        );
    }

    /**
     * @param  array<mixed>  $plan
     */
    private static function string(array $plan, string $key): ?string
    {
        return is_string($plan[$key] ?? null) ? $plan[$key] : null;
    }
}
