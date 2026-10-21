<?php

declare(strict_types=1);

namespace App\Services\NodeStates;

use App\Models\NodeStateVersion;

/**
 * The outcome of rendering one server's NodeState: the version the daemon is
 * served (new, unchanged, or the previous one when the render was invalid),
 * and on failure the error and the in-flight deployments it is attributed to.
 */
final readonly class NodeStateRender
{
    /**
     * @param  list<int>  $deployments  in-flight deployment ids the failure is attributed to
     */
    public function __construct(
        public NodeStateVersion $version,
        public ?string $error = null,
        public array $deployments = [],
    ) {}

    public function failed(): bool
    {
        return $this->error !== null;
    }
}
