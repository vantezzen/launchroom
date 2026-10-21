<?php

declare(strict_types=1);

namespace App\Services\Git\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * A GitLab/Gitea API call returned an unexpected status (or no answer: 0).
 * The message carries the provider, method, path and the provider's error
 * text only: never headers or tokens.
 */
final class GitProviderRequestFailed extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(string $provider, string $method, string $path, Response $response): self
    {
        $detail = $response->json('message') ?? $response->json('error_description') ?? $response->json('error');
        $detail = is_string($detail) ? ': '.mb_substr($detail, 0, 200) : '';

        return new self("{$provider} {$method} {$path} returned {$response->status()}{$detail}", $response->status());
    }

    public function isNotFound(): bool
    {
        return $this->status === 404;
    }

    public function isUnauthorized(): bool
    {
        return $this->status === 401;
    }
}
