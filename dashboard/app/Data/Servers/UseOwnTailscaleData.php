<?php

declare(strict_types=1);

namespace App\Data\Servers;

use Spatie\LaravelData\Attributes\Hidden;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * "Bring your own Tailscale" (docs/05 §2): the tailnet's login server
 * (https://controlplane.tailscale.com, or your own Headscale) and an auth key
 * that may register several servers (reusable, ideally tagged). The key is a
 * secret: hidden from serialization, stored envelope-encrypted.
 */
final class UseOwnTailscaleData extends Data
{
    public function __construct(
        #[Max(512), Regex('/^https:\/\/[a-z0-9.-]+(?::[0-9]{1,5})?$/D')]
        public string $loginServer,
        #[Hidden, Max(1024), Regex('/^[\x21-\x7e]+$/D')]
        public string $authKey,
    ) {}

    /**
     * @param  array<array-key, mixed>  $properties
     * @return array<array-key, mixed>
     */
    public static function prepareForPipeline(array $properties): array
    {
        if (is_string($properties['loginServer'] ?? null)) {
            $properties['loginServer'] = rtrim(strtolower(trim($properties['loginServer'])), '/');
        }

        if (is_string($properties['authKey'] ?? null)) {
            $properties['authKey'] = trim($properties['authKey']);
        }

        return $properties;
    }
}
