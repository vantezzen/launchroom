<?php

declare(strict_types=1);

namespace App\Enums\Sessions;

/**
 * Outcome of one statement in the SQL console's history (docs/07).
 */
enum DatabaseQueryStatus: string
{
    /** Sent to the server; the outcome arrives when the session ends. */
    case Sent = 'sent';
    case Ok = 'ok';
    case Error = 'error';
    case Refused = 'refused';
}
