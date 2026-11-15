<?php

declare(strict_types=1);

namespace App\Services\Http\Exceptions;

use App\Enums\Http\WebhookPostFailure;
use RuntimeException;

/**
 * An outbound webhook POST failed. Carries only what is safe to show: the
 * kind of failure, the host and the status, never the URL (it can hold a
 * token) or the response body. Callers word it for their domain.
 */
final class WebhookPostFailed extends RuntimeException
{
    public function __construct(
        public readonly WebhookPostFailure $failure,
        public readonly string $host,
        public readonly ?int $status = null,
    ) {
        parent::__construct($failure->value.($status === null ? '' : " {$status}"));
    }
}
