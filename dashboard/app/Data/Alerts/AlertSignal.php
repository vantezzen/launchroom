<?php

declare(strict_types=1);

namespace App\Data\Alerts;

use App\Actions\Alerts\RaiseAlert;
use App\Enums\Alerts\AlertRule;
use App\Enums\Alerts\AlertSeverity;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Team;
use InvalidArgumentException;

/**
 * What a caller of {@see RaiseAlert} reports (docs/15
 * §3.2). Text goes to third-party chat services: never secrets, variable
 * values or log excerpts with user data.
 *
 * - `subject`: a stable key of the broken thing, `<kind>:<id>` with a public
 *   id (`service:svc_…`, `server:srv_…`, `domain:dom_…`, `backup:bks_…`);
 * - `facts`: at most 8 short display pairs ("Commit" => "3b7d5e1");
 * - `occurrence`: identifies a new occurrence of a firing alert (a new failed
 *   deployment or backup run), for repeat notifications.
 */
final readonly class AlertSignal
{
    public const string SUBJECT_PATTERN = '/^[a-z][a-z_]{1,19}:[A-Za-z0-9_.:-]{1,96}$/D';

    /**
     * @param  array<string, string>  $facts
     */
    public function __construct(
        public Team $team,
        public AlertRule $rule,
        public string $subject,
        public string $title,
        public string $summary,
        public ?AlertSeverity $severity = null,
        public ?string $href = null,
        public ?Project $project = null,
        public ?Environment $environment = null,
        public ?ServiceInstance $serviceInstance = null,
        public ?Server $server = null,
        public array $facts = [],
        public ?string $occurrence = null,
    ) {
        if (preg_match(self::SUBJECT_PATTERN, $subject) !== 1) {
            throw new InvalidArgumentException("Alert subject [{$subject}] must look like `kind:id`.");
        }

        if (trim($title) === '' || mb_strlen($title) > 200 || mb_strlen($summary) > 1000) {
            throw new InvalidArgumentException('An alert needs a title (≤ 200 characters) and a summary of ≤ 1000 characters.');
        }

        if (count($facts) > 8) {
            throw new InvalidArgumentException('An alert carries at most 8 facts.');
        }

        foreach ($facts as $key => $value) {
            if (mb_strlen((string) $key) > 40 || mb_strlen($value) > 200) {
                throw new InvalidArgumentException('Alert facts are short strings (keys ≤ 40, values ≤ 200 characters).');
            }
        }

        if ($occurrence !== null && (strlen($occurrence) > 64 || $occurrence === '')) {
            throw new InvalidArgumentException('An alert occurrence is 1–64 characters.');
        }

        foreach ([$project, $environment, $serviceInstance, $server] as $model) {
            if ($model !== null && $model->team_id !== $team->id) {
                throw new InvalidArgumentException('Alert context must belong to the alert\'s team.');
            }
        }
    }
}
