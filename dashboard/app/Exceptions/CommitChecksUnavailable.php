<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/**
 * The CI checks of a commit could not be read (the provider didn't answer,
 * refused, or the repository is no longer reachable). The message is
 * user-facing; the gate keeps waiting and retries (docs/06 §2.5).
 */
final class CommitChecksUnavailable extends DomainException {}
