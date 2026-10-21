<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use App\Data\Backups\ServiceBackupStatusData;
use App\Enums\ChangeEffect;
use App\Enums\ServiceKind;
use Spatie\LaravelData\Data;

/**
 * One service on the canvas of an environment (docs/08 §3.3).
 */
final class CanvasNodeData extends Data
{
    public function __construct(
        /** Service public id (stable across environments). */
        public string $id,
        public string $instanceId,
        public string $name,
        public string $slug,
        public ServiceKind $kind,
        /** Glyph key: an engine, framework or source ("postgres", "laravel", "github", "docker"). */
        public string $logo,
        /** What it runs: "PostgreSQL 18", "nginx:1.27", "acme/shop". */
        public string $subtitle,
        public NodeStatusData $status,
        public ?string $domain,
        /** Address on the environment network: `http://api:8000` or `postgres:5432`. */
        public ?string $privateAddress,
        public int $replicas,
        /** Mount path of the first volume, if any. */
        public ?string $volume,
        /** Server names the active deployment runs on ("hel-1, fra-2"). */
        public ?string $server,
        /** Strongest staged effect, null when nothing is staged for this service. */
        public ?ChangeEffect $staged,
        /** Removal staged: the node goes away with the next deploy. */
        public bool $removed,
        public bool $enabled,
        /** Parent app of a worker or cron. */
        public ?string $parentId,
        public ?CanvasPositionData $position,
        /** Databases: backup health ("Backed up 3 hours ago", docs/14 §3). */
        public ?ServiceBackupStatusData $backup = null,
        /** Degraded: its server is down, or replicas found no other server (docs/02 §6). */
        public ?PlacementIssueData $placementIssue = null,
    ) {}
}
