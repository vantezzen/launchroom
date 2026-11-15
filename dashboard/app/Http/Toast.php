<?php

declare(strict_types=1);

namespace App\Http;

use App\Data\Inertia\ToastData;
use App\Enums\ToastType;
use Inertia\Inertia;

/** Flashes a toast for the next page (design-system: toasts only for async outcomes or undo). */
final class Toast
{
    public const string FLASH_KEY = 'toast';

    public static function show(ToastData $toast): void
    {
        Inertia::flash(self::FLASH_KEY, $toast->toArray());
    }

    public static function flash(ToastType $type, string $message): void
    {
        self::show(new ToastData($type, $message));
    }

    public static function success(string $message): void
    {
        self::flash(ToastType::Success, $message);
    }

    public static function info(string $message): void
    {
        self::flash(ToastType::Info, $message);
    }

    public static function warning(string $message): void
    {
        self::flash(ToastType::Warning, $message);
    }

    public static function error(string $message): void
    {
        self::flash(ToastType::Error, $message);
    }
}
