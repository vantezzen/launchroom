<?php

declare(strict_types=1);

namespace App\Actions\Registry;

use App\Data\Registry\IssuedRegistryToken;
use App\Data\Registry\RegistryScope;
use App\Services\Registry\RegistryTokenIssuer;

/**
 * The Docker token-spec realm (GET /registry/token, docs/05 §6). Zot names it
 * in its WWW-Authenticate challenge. The Basic password must be a registry
 * token this dashboard issued (daemons get theirs from
 * POST /daemon/v1/registry-token); the response narrows it to the requested
 * scopes. It never widens access and never outlives the presented token, so
 * the realm cannot be used to renew or escalate a token.
 */
final readonly class IssueRealmRegistryToken
{
    public function __construct(
        private RegistryTokenIssuer $issuer,
    ) {}

    /**
     * @param  list<string>  $requestedScopes  raw `scope` parameters (space-separated lists allowed)
     */
    public function handle(#[\SensitiveParameter] string $presentedToken, array $requestedScopes): ?IssuedRegistryToken
    {
        $presented = $this->issuer->verify($presentedToken);

        if (! $presented instanceof IssuedRegistryToken) {
            return null;
        }

        $remaining = (int) floor(now()->diffInSeconds($presented->expiresAt, false));

        if ($remaining < 1) {
            return null;
        }

        $granted = [];

        foreach ($presented->scopes as $scope) {
            $granted[$scope->repository] = $scope->actions;
        }

        $scopes = [];

        foreach ($requestedScopes as $raw) {
            foreach (preg_split('/\s+/', trim($raw)) ?: [] as $one) {
                $scope = RegistryScope::fromScopeString($one);

                if (! $scope instanceof RegistryScope || ! isset($granted[$scope->repository])) {
                    continue;
                }

                $actions = array_values(array_unique([
                    ...($scopes[$scope->repository]->actions ?? []),
                    ...array_intersect($scope->actions, $granted[$scope->repository]),
                ]));

                if ($actions !== [] && (isset($scopes[$scope->repository]) || count($scopes) < 16)) {
                    $scopes[$scope->repository] = new RegistryScope($scope->repository, $actions);
                }
            }
        }

        return $this->issuer->issue('realm', array_values($scopes), $remaining);
    }
}
