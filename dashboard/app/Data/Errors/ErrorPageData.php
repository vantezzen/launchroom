<?php

declare(strict_types=1);

namespace App\Data\Errors;

use App\Enums\Http\ErrorPageKind;
use App\Enums\Http\MissingResource;
use Spatie\LaravelData\Data;

/**
 * Props of `errors/show` (docs/08 §24). Never carries the exception's
 * message, a trace or anything about rows outside the user's reach.
 */
final class ErrorPageData extends Data
{
    public function __construct(
        public int $status,
        public ErrorPageKind $kind,
        /** The requested path, without the query string. */
        public string $path,
        /** 404 on a `/p/…` or `/servers/…` path: the level that didn't resolve. */
        public ?MissingResource $missing = null,
        /** @var list<ErrorSuggestionData> */
        public array $suggestions = [],
        /** 5xx: the id the reported exception is logged under. */
        public ?string $reference = null,
        /** 429 and 503: seconds from `Retry-After`. */
        public ?int $retryAfter = null,
        /** 419: the same-origin page to reload. */
        public ?string $reloadTo = null,
    ) {}
}
