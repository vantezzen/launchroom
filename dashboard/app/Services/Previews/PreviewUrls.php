<?php

declare(strict_types=1);

namespace App\Services\Previews;

use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Services\Domains\GeneratedDomains;
use App\Support\Hostname;

/**
 * Generated URLs beyond the per-environment domain (docs/05 §5):
 *
 * - **immutable per-deployment URLs** `{service}-{shortid}.{project}.{apps}`
 *   (sslip: `{service}-{shortid}-{project}.{ip}.sslip.io`), where shortid is
 *   the lowercased last 8 characters of the deployment's ULID, routed to that
 *   deployment's workloads while they run (active or draining);
 * - **branch aliases** for previews `{service}-git-{branch}.{project}.{apps}`.
 *
 * Labels longer than 63 characters are truncated with a hash suffix.
 */
final readonly class PreviewUrls
{
    public function __construct(
        private GeneratedDomains $domains,
    ) {}

    public static function shortId(Deployment $deployment): string
    {
        return strtolower(substr($deployment->public_id, -8));
    }

    public function deploymentHostname(Service $service, Deployment $deployment, Project $project): ?string
    {
        return $this->domains->hostnameWith($service, self::shortId($deployment), $project);
    }

    public function branchHostname(Service $service, string $branch, Project $project): ?string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($branch)), '-');

        return $slug === '' ? null : $this->domains->hostnameWith($service, Hostname::label('git', $slug), $project);
    }

    /**
     * Which environment (and, for an immutable URL, which deployment) a
     * routed hostname belongs to. Null for hosts launchroom doesn't route.
     *
     * @return array{environment: Environment, deployment: Deployment|null}|null
     */
    public function resolve(string $host): ?array
    {
        $host = strtolower($host);

        if (! Hostname::isValid($host) && ! Hostname::isLocal($host)) {
            return null;
        }

        $domain = Domain::query()->where('hostname', $host)->with('serviceInstance.environment')->first();

        if ($domain instanceof Domain) {
            return ['environment' => $domain->serviceInstance->environment, 'deployment' => null];
        }

        $first = explode('.', $host)[0];

        if (preg_match('/-([0-9a-z]{8})(?:-|$)/', $first, $m) !== 1) {
            return null;
        }

        $candidates = Deployment::query()
            ->whereRaw('lower(right(public_id, 8)) = ?', [$m[1]])
            ->with(['serviceInstance.service', 'environment.project'])
            ->limit(5)
            ->get();

        foreach ($candidates as $deployment) {
            $environment = $deployment->environment;

            if ($this->deploymentHostname($deployment->serviceInstance->service, $deployment, $environment->project) === $host) {
                return ['environment' => $environment, 'deployment' => $deployment];
            }
        }

        return null;
    }
}
