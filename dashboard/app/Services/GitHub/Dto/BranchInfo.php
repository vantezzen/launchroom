<?php

declare(strict_types=1);

namespace App\Services\GitHub\Dto;

use App\Services\GitHub\JsonObject;

final readonly class BranchInfo
{
    public function __construct(
        public string $name,
        public string $sha,
        public bool $protected,
    ) {}

    public static function fromJson(JsonObject $json): self
    {
        return new self(
            name: $json->string('name'),
            sha: $json->object('commit')->string('sha'),
            protected: $json->bool('protected'),
        );
    }
}
