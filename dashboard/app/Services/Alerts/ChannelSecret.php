<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Exceptions\DecryptionFailed;
use LogicException;
use SensitiveParameter;

/**
 * A channel's decrypted secrets, in memory only: the Slack/Discord/webhook
 * URL and the webhook signing secret. Never serializable and redacted in
 * dumps, logs and traces (docs/09 R12, R16).
 */
final readonly class ChannelSecret
{
    public function __construct(
        #[SensitiveParameter]
        public string $url,
        #[SensitiveParameter]
        public ?string $signingSecret = null,
    ) {}

    /**
     * The stored JSON form `{url, signingSecret?}`.
     *
     * @param  array<string, string>  $values
     *
     * @throws DecryptionFailed
     */
    public static function of(#[SensitiveParameter] array $values): self
    {
        return new self($values['url'] ?? throw DecryptionFailed::malformed(), $values['signingSecret'] ?? null);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->signingSecret === null ? ['url' => $this->url] : ['url' => $this->url, 'signingSecret' => $this->signingSecret];
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['url' => '[redacted]', 'signingSecret' => '[redacted]'];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('Channel secrets cannot be serialized.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Channel secrets cannot be unserialized.');
    }
}
