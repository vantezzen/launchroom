<?php

declare(strict_types=1);

namespace App\Data\Activity;

use Spatie\LaravelData\Data;

/**
 * One item of the activity feed (team page and project filter, direction.md
 * §5.4): "**mara** deployed api and web to production". `title` is the
 * sentence after the actor; `detail` the commit or revision message.
 *
 * Kinds: deploy, rollback, redeploy (deployments of one commit/revision,
 * grouped), promotion, created (environment created from another), service,
 * project, server, member, alert. `status` is the outcome of a deploy group
 * (live, failed, deploying, cancelled, ready); of an alert `firing` or
 * `resolved` (kind alert, docs/15 §3.6).
 */
final class ActivityEntryData extends Data
{
    public function __construct(
        public string $id,
        public string $kind,
        public string $at,
        public ?string $actor,
        public string $title,
        public ?string $detail,
        public ?string $environmentId,
        public ?string $environmentName,
        public ?string $href,
        public ?string $projectId = null,
        public ?string $projectName = null,
        public ?string $status = null,
        public ?string $sha = null,
        /** Alerts: warning | critical. */
        public ?string $severity = null,
    ) {}
}
