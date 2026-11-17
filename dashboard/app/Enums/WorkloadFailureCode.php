<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a container couldn't be pulled, created or started, classified by
 * launchroomd at the source (report.json WorkloadStatus.failure.code and
 * WorkloadEvent.code, Go `state.WorkloadFailureCode`). Read leniently: an
 * unknown code reads as none.
 */
enum WorkloadFailureCode: string
{
    case CommandNotFound = 'command_not_found';
    case NotExecutable = 'not_executable';
    case WrongPlatform = 'wrong_platform';
    case MountFailed = 'mount_failed';
    case InvalidUser = 'invalid_user';
    case ImageNotFound = 'image_not_found';
    case Unauthorized = 'unauthorized';
    case RateLimited = 'rate_limited';
    case Network = 'network';
    case NoSpace = 'no_space';
    case Other = 'other';
}
