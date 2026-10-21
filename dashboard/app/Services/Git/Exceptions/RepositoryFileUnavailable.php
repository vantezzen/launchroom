<?php

declare(strict_types=1);

namespace App\Services\Git\Exceptions;

use RuntimeException;

/**
 * The provider can't read files at a ref (plain git servers have no file
 * API, docs/19 §1), or the repository/ref isn't reachable right now. A
 * missing file is not this: `RepositoryFiles::read()` returns null then.
 */
final class RepositoryFileUnavailable extends RuntimeException {}
