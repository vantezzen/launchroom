<?php

declare(strict_types=1);

namespace App\Services\Registry;

use App\Data\Registry\RegistryScope;
use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Marks deployments whose built image the registry no longer has
 * (`deployments.image_pruned_at`), so they are not offered for rollback
 * (docs/04 §8: Zot keeps the last N images per service, `apps/**`
 * retention). Scheduled; only definite answers count: a 404 for the
 * manifest digest marks the image pruned, any other outcome leaves it alone.
 *
 * Only images under `launchroom.images.apps_prefix` are checked: mirrored
 * upstream images can always be re-fetched by digest.
 */
final readonly class PrunedImages
{
    private const array ACCEPT = [
        'application/vnd.oci.image.index.v1+json',
        'application/vnd.oci.image.manifest.v1+json',
        'application/vnd.docker.distribution.manifest.list.v2+json',
        'application/vnd.docker.distribution.manifest.v2+json',
    ];

    public function __construct(
        private HttpFactory $http,
        private RegistryTokenIssuer $tokens,
    ) {}

    /**
     * @return int deployments newly marked
     */
    public function markPruned(int $limit = 500): int
    {
        $prefix = rtrim(config()->string('launchroom.images.apps_prefix'), '/').'/';

        /** @var list<string> $images */
        $images = Deployment::query()
            ->whereNull('image_pruned_at')
            ->whereIn('status', [DeploymentStatus::Superseded->value, DeploymentStatus::Active->value])
            ->where('image', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix).'%')
            ->distinct()
            ->limit($limit)
            ->pluck('image')
            ->all();

        $marked = 0;

        foreach ($images as $image) {
            if ($this->isPruned($prefix, $image)) {
                $marked += Deployment::query()->where('image', $image)->whereNull('image_pruned_at')->update(['image_pruned_at' => now()]);
            }
        }

        if ($marked > 0) {
            Log::info('Deployments whose images the registry pruned are no longer rollback targets.', ['deployments' => $marked]);
        }

        return $marked;
    }

    private function isPruned(string $prefix, string $image): bool
    {
        // <prefix>apps/<service>@sha256:<hex> (the apps prefix includes "apps").
        if (preg_match('/^'.preg_quote($prefix, '/').'([a-z0-9_]+)@(sha256:[a-f0-9]{64})$/D', $image, $m) !== 1) {
            return false;
        }

        $repository = ltrim((string) parse_url('http://'.$prefix.$m[1], PHP_URL_PATH), '/');
        $url = rtrim(config()->string('launchroom.registry_lookup.url'), '/')."/v2/{$repository}/manifests/{$m[2]}";

        try {
            $token = $this->tokens->issue('dashboard', [new RegistryScope($repository, [RegistryScope::PULL])], 60);
            $response = $this->http
                ->timeout(config()->integer('launchroom.registry_lookup.timeout_seconds'))
                ->withoutRedirecting()
                ->withToken($token->token)
                ->accept(implode(', ', self::ACCEPT))
                ->head($url);
        } catch (Throwable) {
            return false;
        }

        return $response->status() === 404;
    }
}
