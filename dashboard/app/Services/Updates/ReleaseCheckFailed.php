<?php

declare(strict_types=1);

namespace App\Services\Updates;

use RuntimeException;

/**
 * The release check could not get a usable answer (message is user-safe).
 */
final class ReleaseCheckFailed extends RuntimeException {}
