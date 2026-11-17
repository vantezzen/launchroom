<?php

declare(strict_types=1);

namespace App\Data\Registry;

/**
 * What deploying a private image needs to know (App\Actions\Registry\MirrorPrivateImage):
 *
 * - ready: `image` is the digest-pinned team mirror reference servers pull;
 * - pending: the copy is queued or running, try again later;
 * - failed: `message` says why, in words for the deployment.
 */
final readonly class PrivateImageResult
{
    private function __construct(
        public string $state,
        public ?string $image = null,
        public ?string $message = null,
    ) {}

    public static function ready(string $image): self
    {
        return new self('ready', image: $image);
    }

    public static function pending(): self
    {
        return new self('pending');
    }

    public static function failed(string $message): self
    {
        return new self('failed', message: $message);
    }

    public function isReady(): bool
    {
        return $this->state === 'ready';
    }

    public function isPending(): bool
    {
        return $this->state === 'pending';
    }

    public function isFailed(): bool
    {
        return $this->state === 'failed';
    }
}
