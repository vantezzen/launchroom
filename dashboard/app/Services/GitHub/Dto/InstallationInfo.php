<?php

declare(strict_types=1);

namespace App\Services\GitHub\Dto;

use App\Enums\Git\RepositorySelection;
use App\Services\GitHub\JsonObject;

/**
 * An installation as GitHub describes it (`GET /app/installations/{id}` or
 * the `installation` object of installation webhooks).
 */
final readonly class InstallationInfo
{
    public function __construct(
        public int $id,
        public int $appId,
        public int $accountId,
        public string $accountLogin,
        public string $accountType,
        public RepositorySelection $repositorySelection,
        public bool $suspended,
    ) {}

    public static function fromJson(JsonObject $json): self
    {
        $account = $json->object('account');

        return new self(
            id: $json->int('id'),
            appId: $json->int('app_id'),
            accountId: $account->int('id'),
            accountLogin: $account->string('login'),
            accountType: $account->stringOrNull('type') ?? 'User',
            repositorySelection: RepositorySelection::tryFrom($json->stringOrNull('repository_selection') ?? '') ?? RepositorySelection::Selected,
            suspended: $json->has('suspended_at'),
        );
    }
}
