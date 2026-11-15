<?php

declare(strict_types=1);

namespace App\Services\Registry;

use App\Data\Registry\RegistryScope;
use App\Support\Images\ImageReference;
use App\Support\Images\RegistryLayout;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

/**
 * Is an image still in the registry? `HEAD /v2/<repository>/manifests/<digest>`
 * on the dashboard's own view of Zot (`launchroom.registry_lookup.url`) with a
 * pull token for exactly that repository.
 *
 * Used after a control-plane restore (docs/14 §5.2 step e: Zot is empty
 * unless its storage was on S3; active deployments whose image is gone are
 * rebuilt from their recorded commit) and for pruned rollback targets
 * ({@see PrunedImages}).
 */
final readonly class RegistryManifests
{
    public function __construct(
        private HttpFactory $http,
        private RegistryTokenIssuer $tokens,
    ) {}

    /**
     * true: present, false: missing, null: not in launchroom's registry
     * (`127.0.0.1:5000/…`) or unknown (registry unreachable).
     */
    public function exists(string $image): ?bool
    {
        $registry = RegistryLayout::registry();

        if (! str_starts_with($image, $registry) || preg_match('#^([a-z0-9][a-z0-9._/-]{0,250})@('.ImageReference::DIGEST_BODY.')$#D', substr($image, strlen($registry)), $matches) !== 1) {
            return null;
        }

        return $this->head($matches[1], $matches[2]);
    }

    /**
     * true: present, false: a definite 404, null: anything else.
     */
    public function head(string $repository, string $digest): ?bool
    {
        $url = rtrim(config()->string('launchroom.registry_lookup.url'), '/')."/v2/{$repository}/manifests/{$digest}";

        try {
            $token = $this->tokens->issue('dashboard', [new RegistryScope($repository, [RegistryScope::PULL])], 120);
            $response = $this->http
                ->timeout(config()->integer('launchroom.registry_lookup.timeout_seconds'))
                ->withoutRedirecting()
                ->withToken($token->token)
                ->accept(implode(', ', RegistryLayout::MANIFEST_ACCEPT))
                ->head($url);
        } catch (Throwable) {
            return null;
        }

        return match (true) {
            $response->successful() => true,
            $response->status() === 404 => false,
            default => null,
        };
    }
}
