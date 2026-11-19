<?php

declare(strict_types=1);

namespace App\Data\Backups;

use SensitiveParameter;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * "Also protect launchroom itself" from the launchroom row: which team
 * storage to copy, and the repository password of a launchroom folder that
 * already holds backups (to reconnect them).
 */
final class CopyDestinationData extends Data
{
    public function __construct(
        #[Max(40)]
        public string $from,
        #[Min(16), Max(256), SensitiveParameter]
        public ?string $repositoryPassword = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['from' => $this->from, 'repositoryPassword' => '[redacted]'];
    }
}
