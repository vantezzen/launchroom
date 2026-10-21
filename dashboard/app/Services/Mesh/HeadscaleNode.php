<?php

declare(strict_types=1);

namespace App\Services\Mesh;

/**
 * A node as Headscale's API reports it (only what the dashboard uses).
 */
final readonly class HeadscaleNode
{
    /**
     * @param  list<string>  $ipAddresses
     * @param  list<string>  $tags
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $ipAddresses,
        public array $tags,
        public bool $online,
        public ?string $preAuthKeyId,
    ) {}
}
