<?php

declare(strict_types=1);

namespace App\Data\Api\Events;

use App\Data\Deployments\LogLineData;
use Spatie\LaravelData\Data;

/**
 * `lines` of a deployment log stream; the event id is `cursor`.
 */
final class DeploymentLinesEvent extends Data
{
    /**
     * @param  list<LogLineData>  $lines
     */
    public function __construct(
        public array $lines,
        public int $cursor,
    ) {}
}
