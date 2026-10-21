<?php

declare(strict_types=1);

namespace App\Services\Git\Exceptions;

use RuntimeException;

/**
 * The connection can't be used right now (not finished, token refused,
 * host key not confirmed). The message is shown to users as is.
 */
final class GitConnectionUnusable extends RuntimeException {}
