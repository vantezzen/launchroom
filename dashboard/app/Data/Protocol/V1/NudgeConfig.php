<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Data;

/**
 * Where the daemon receives realtime nudges (docs/03 §4). `url` omitted: the
 * daemon derives ws(s)://<control-plane host>/app/<key>.
 */
final class NudgeConfig extends Data
{
    public function __construct(
        public string $key,
        public ?string $url = null,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toProtocol(): array
    {
        return array_filter(['key' => $this->key, 'url' => $this->url], static fn (?string $value): bool => $value !== null);
    }
}
