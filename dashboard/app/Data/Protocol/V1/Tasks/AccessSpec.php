<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1\Tasks;

use App\Data\Protocol\V1\Sealed;
use App\Enums\DaemonTaskType;

/**
 * Temporary remote access to a container, a database or a volume (protocol
 * AccessSpec, docs/03 §12). `password` is sealed at lease time.
 */
final class AccessSpec extends TaskSpec
{
    /**
     * @param  list<string>  $allowFrom
     * @param  list<string>|null  $authorizedKeys
     * @param  list<string>|null  $hosts
     */
    public function __construct(
        public string $access,
        public string $kind,
        public string $workload,
        public string $user,
        public array $allowFrom,
        public string $idleTimeout,
        public ?string $engine = null,
        public ?array $authorizedKeys = null,
        public ?array $hosts = null,
        public ?bool $allowWrites = null,
        public ?string $volume = null,
        public ?string $image = null,
        public ?Sealed $password = null,
    ) {}

    public function type(): DaemonTaskType
    {
        return DaemonTaskType::Access;
    }
}
