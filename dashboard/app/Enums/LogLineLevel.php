<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Logs\LogLevel;

/**
 * The level of a stored log line (`lines[].level`), guessed at ingest by
 * {@see LogLevel}.
 */
enum LogLineLevel: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Info = 'info';
    case Debug = 'debug';
}
