<?php

declare(strict_types=1);

namespace App\Actions\Api;

use App\Data\Api\CliSessionData;
use App\Data\Api\StartCliSessionInput;
use App\Models\CliLoginRequest;
use App\Support\Api\CliUserCode;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Starts a CLI sign-in (docs/16 §4): a 256-bit device code (stored as its
 * SHA-256) for the CLI to poll with, and a short user code the person
 * compares in the browser. Unauthenticated: nothing is granted until a
 * member approves this exact code.
 */
final class StartCliLogin
{
    public function handle(StartCliSessionInput $input, ?string $ip, ?string $userAgent): CliSessionData
    {
        $deviceCode = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expires = config()->integer('api.cli_login.expires_seconds');

        for ($attempt = 0; ; $attempt++) {
            $userCode = CliUserCode::generate();

            try {
                CliLoginRequest::query()->create([
                    'device_code_hash' => hash('sha256', $deviceCode),
                    'user_code' => $userCode,
                    'client_name' => $input->clientName,
                    'client_hostname' => $input->hostname,
                    'client_version' => $input->version,
                    'ip' => $ip,
                    'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 512),
                    'expires_at' => now()->addSeconds($expires),
                ]);

                break;
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 4) {
                    throw $e;
                }
            }
        }

        $url = route('cli-login.show');

        return new CliSessionData(
            deviceCode: $deviceCode,
            userCode: $userCode,
            verificationUrl: $url,
            verificationUrlComplete: $url.'?'.http_build_query(['code' => $userCode]),
            expiresIn: $expires,
            interval: config()->integer('api.cli_login.interval_seconds'),
        );
    }
}
