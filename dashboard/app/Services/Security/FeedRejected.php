<?php

declare(strict_types=1);

namespace App\Services\Security;

use RuntimeException;

/**
 * An advisory source answered with something launchroom won't use: a 4xx
 * other than 429, or a document that fails parsing or its grammar. Dropped
 * (docs/22 §4.4).
 */
final class FeedRejected extends RuntimeException {}
