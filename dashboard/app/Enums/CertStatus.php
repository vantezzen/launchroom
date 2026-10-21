<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A domain's certificate state as edges report it (Report.ingress.certificates,
 * docs/05 §5). Null on a domain: no edge has reported it yet.
 */
enum CertStatus: string
{
    /** A certificate covering the host is stored and not expired. */
    case Issued = 'issued';
    /** None yet: custom domains get theirs on the first request after DNS points at an edge. */
    case Pending = 'pending';
    /** The last attempt to obtain one failed and none is usable. */
    case Failed = 'failed';
}
