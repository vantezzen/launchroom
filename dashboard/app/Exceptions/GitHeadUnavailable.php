<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/**
 * The commit a branch points at could not be determined (repository gone,
 * branch missing, GitHub or the remote unreachable). The message is user-facing.
 */
final class GitHeadUnavailable extends DomainException {}
