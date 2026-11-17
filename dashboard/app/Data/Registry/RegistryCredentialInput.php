<?php

declare(strict_types=1);

namespace App\Data\Registry;

use SensitiveParameter;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * Add or change a team's sign-in details for a private registry (D91). The
 * password or token is optional on update (blank keeps the stored one).
 */
final class RegistryCredentialInput extends Data
{
    public function __construct(
        #[Min(4), Max(260)]
        public string $host,
        #[Min(1), Max(255)]
        public string $username,
        #[Max(4096), SensitiveParameter]
        public ?string $password = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['host' => $this->host, 'username' => $this->username, 'password' => '[redacted]'];
    }
}
