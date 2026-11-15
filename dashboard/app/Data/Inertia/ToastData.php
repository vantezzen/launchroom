<?php

declare(strict_types=1);

namespace App\Data\Inertia;

use App\Enums\ToastType;
use Spatie\LaravelData\Data;

/** A one-line toast the next page shows (flashed under `toast`, read by useFlashToast). */
final class ToastData extends Data
{
    public function __construct(
        public ToastType $type,
        public string $message,
    ) {}
}
