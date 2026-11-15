<?php

declare(strict_types=1);

namespace App\Enums\Domains;

enum DnsLookupStatus: string
{
    case Ok = 'ok';
    /** The name doesn't exist. */
    case NxDomain = 'nxdomain';
    /** The name exists without A/AAAA records. */
    case Empty = 'empty';
    /** No resolver answered in time. */
    case Error = 'error';
}
