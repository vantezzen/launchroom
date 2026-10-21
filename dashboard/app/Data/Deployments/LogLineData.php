<?php

declare(strict_types=1);

namespace App\Data\Deployments;

use Spatie\LaravelData\Data;

/**
 * One log line of a deployment. `number` is stable for the deployment
 * (anchors `#L42`); `section` is the timeline step it belongs to (plan,
 * build, release, rollout, health, route, runtime); `step` the finer build
 * step reported by the daemon (install, build, push, …). Text is raw and
 * rendered as text, never as HTML.
 */
final class LogLineData extends Data
{
    public function __construct(
        public int $number,
        public string $time,
        public string $stream,
        public string $text,
        public string $section,
        public ?string $step,
    ) {}
}
