<?php

declare(strict_types=1);

namespace App\Support\Compose;

use RuntimeException;

/**
 * A `${…}` expression of one value can't be used (bad syntax, a required
 * value is missing, or a generated value is used outside a variable).
 */
final class InterpolationFailed extends RuntimeException {}
