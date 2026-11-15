<?php

declare(strict_types=1);

namespace App\Services\Git;

use App\Enums\Git\GitProviderKind;
use App\Enums\Git\GitServerKind;
use App\Exceptions\DomainRuleViolation;
use App\Services\Http\Exceptions\UnsafeUrl;
use App\Services\Http\SafeHttpClient;
use Throwable;

/**
 * Checks a GitLab/Gitea base URL before anything is stored (docs/19 §4.1):
 * reachable through SafeHttpClient (private networks only with the owner
 * override, which also allows plain http), and actually that kind of server
 * (the provider decides).
 */
final readonly class GitServerProbe
{
    public function __construct(
        private SafeHttpClient $http,
        private GitProviders $providers,
    ) {}

    /**
     * @return array{string, GitServerKind} the normalized origin and the detected server
     *
     * @throws DomainRuleViolation
     */
    public function probe(GitProviderKind $provider, string $baseUrl, bool $privateNetwork): array
    {
        $origin = strtolower(rtrim(trim($baseUrl), '/'));
        $client = $privateNetwork ? $this->http->withPrivateNetwork() : $this->http;

        if (! $privateNetwork && ! str_starts_with($origin, 'https://')) {
            throw DomainRuleViolation::on('baseUrl', 'Use an https:// address. Only an instance owner can allow plain http for a server on a private network.');
        }

        try {
            $api = $this->providers->api($provider);
            $kind = $api->recognize($client->get($api->probeUrl($origin)), $origin);
        } catch (DomainRuleViolation $e) {
            throw $e;
        } catch (UnsafeUrl $e) {
            throw DomainRuleViolation::on('baseUrl', "launchroom can’t connect there: {$e->getMessage()}.".($privateNetwork ? '' : ' An instance owner can allow servers on a private network.'));
        } catch (Throwable) {
            throw DomainRuleViolation::on('baseUrl', "launchroom couldn’t reach {$origin}. Check the address.");
        }

        return [$origin, $kind];
    }
}
