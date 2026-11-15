<?php

declare(strict_types=1);

namespace App\Enums\Http;

enum WebhookPostFailure: string
{
    case UnsafeUrl = 'unsafe_url';
    case ResponseTooLarge = 'response_too_large';
    case Unreachable = 'unreachable';
    case Failed = 'failed';
    case Status = 'status';
}
