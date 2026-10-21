<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use Spatie\LaravelData\Data;

/**
 * Why a service isn't fully placed right now (`service_instances.placement_issue`):
 * `stateful_server_down` (degraded: its volume's server is down, never moved
 * automatically) or `unplaceable` (replicas of a dead server found no other
 * server). Drives the degraded banner.
 */
final class PlacementIssueData extends Data
{
    public function __construct(
        public string $kind,
        public string $message,
        /** The server it concerns (public id), for the "restore elsewhere" link. */
        public ?string $serverId,
        public string $since,
    ) {}

    /**
     * @param  array<string, mixed>|null  $issue
     */
    public static function fromStored(?array $issue): ?self
    {
        if (! is_array($issue) || ! is_string($issue['kind'] ?? null) || ! is_string($issue['message'] ?? null)) {
            return null;
        }

        return new self(
            $issue['kind'],
            $issue['message'],
            is_string($issue['serverId'] ?? null) ? $issue['serverId'] : null,
            is_string($issue['since'] ?? null) ? $issue['since'] : '',
        );
    }
}
