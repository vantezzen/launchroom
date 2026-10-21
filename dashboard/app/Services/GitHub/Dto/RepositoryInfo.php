<?php

declare(strict_types=1);

namespace App\Services\GitHub\Dto;

use App\Services\GitHub\JsonObject;
use Carbon\CarbonImmutable;

/**
 * A repository as GitHub describes it. Webhook `repositories` lists of
 * installation events only carry id/name/full_name/private, so the rest is
 * optional and filled in by the next refresh.
 */
final readonly class RepositoryInfo
{
    public function __construct(
        public int $id,
        public string $fullName,
        public bool $private,
        public ?string $defaultBranch = null,
        public bool $archived = false,
        public ?CarbonImmutable $pushedAt = null,
    ) {}

    public static function fromJson(JsonObject $json): self
    {
        return new self(
            id: $json->int('id'),
            fullName: $json->string('full_name'),
            private: $json->bool('private', true),
            defaultBranch: $json->stringOrNull('default_branch'),
            archived: $json->bool('archived'),
            pushedAt: $json->timestampOrNull('pushed_at'),
        );
    }
}
