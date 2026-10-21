<?php

declare(strict_types=1);

namespace App\Services\GitHub\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * A GitHub API call returned an unexpected status. The message carries the
 * method, path and GitHub's error message only: never headers or tokens.
 */
final class GitHubRequestFailed extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(string $method, string $path, Response $response): self
    {
        $detail = $response->json('message');
        $detail = is_string($detail) ? ': '.mb_substr($detail, 0, 200) : '';

        return new self("GitHub {$method} {$path} returned {$response->status()}{$detail}", $response->status());
    }

    public function isNotFound(): bool
    {
        return $this->status === 404;
    }
}
