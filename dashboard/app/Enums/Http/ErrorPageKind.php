<?php

declare(strict_types=1);

namespace App\Enums\Http;

/**
 * What an error page says (docs/08 §24): one case per message, several
 * statuses may share one.
 */
enum ErrorPageKind: string
{
    case NotFound = 'not_found';
    case Forbidden = 'forbidden';
    case Expired = 'expired';
    case TooManyRequests = 'too_many_requests';
    case ServerError = 'server_error';
    case Unavailable = 'unavailable';

    /** The page for a status, or null when the status keeps Laravel's own answer. */
    public static function forStatus(int $status): ?self
    {
        return match (true) {
            in_array($status, [404, 405, 410], true) => self::NotFound,
            $status === 403 => self::Forbidden,
            $status === 419 => self::Expired,
            $status === 429 => self::TooManyRequests,
            $status === 503 => self::Unavailable,
            $status >= 500 && $status <= 599 => self::ServerError,
            default => null,
        };
    }
}
