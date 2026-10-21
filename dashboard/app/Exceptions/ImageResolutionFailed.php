<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/**
 * A tag could not be resolved to a digest. The message is user-facing.
 */
final class ImageResolutionFailed extends DomainException {}
