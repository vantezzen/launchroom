<?php

declare(strict_types=1);

namespace App\Data\Servers;

use Spatie\LaravelData\Data;

/**
 * `servers.render_error`: the latest NodeState render failed validation and
 * the previous version stays published (App\Actions\Servers\RenderNodeState).
 */
final class RenderErrorData extends Data
{
    /**
     * @param  list<string>  $problems
     * @param  list<string>  $deployments  public ids of the deployments that caused it
     */
    public function __construct(
        public string $message,
        public array $problems,
        public array $deployments,
        public string $at,
    ) {}
}
