<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A backup an operation waits for couldn't start: thrown inside the
 * operation's transaction to roll it back, then turned into a refusal.
 */
final class BackupRefused extends RuntimeException {}
