<?php

declare(strict_types=1);

namespace App\Data\Registry;

use Spatie\LaravelData\Data;

/**
 * Settings → Private registries (D91).
 */
final class RegistryCredentialsPageData extends Data
{
    /**
     * @param  list<RegistryCredentialData>  $credentials
     */
    public function __construct(
        public array $credentials,
        public bool $canManage,
        public string $mirrorPrefix,
    ) {}
}
