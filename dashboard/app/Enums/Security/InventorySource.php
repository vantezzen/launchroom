<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * Where an inventoried image comes from (docs/22 §3.6): built by launchroom
 * (`apps/…`, lockfiles can set scopes) or pulled from a registry (image
 * services and database engines, `mirror/…`).
 */
enum InventorySource: string
{
    case Built = 'built';
    case Registry = 'registry';
}
