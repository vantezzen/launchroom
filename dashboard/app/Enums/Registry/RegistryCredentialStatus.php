<?php

declare(strict_types=1);

namespace App\Enums\Registry;

/**
 * Result of the last sign-in check against the registry (Check, or the last
 * copy that used the credential).
 */
enum RegistryCredentialStatus: string
{
    case Unverified = 'unverified';
    case Ok = 'ok';
    case Failed = 'failed';
}
