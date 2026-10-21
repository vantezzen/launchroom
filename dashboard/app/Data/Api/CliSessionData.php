<?php

declare(strict_types=1);

namespace App\Data\Api;

use Spatie\LaravelData\Data;

/**
 * A started CLI sign-in (docs/16 §4). The device code is the CLI's secret
 * for polling; the user code is what the person compares in the browser.
 */
final class CliSessionData extends Data
{
    public function __construct(
        public string $deviceCode,
        public string $userCode,
        public string $verificationUrl,
        public string $verificationUrlComplete,
        public int $expiresIn,
        public int $interval,
    ) {}
}
