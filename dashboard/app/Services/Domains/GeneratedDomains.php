<?php

declare(strict_types=1);

namespace App\Services\Domains;

use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Support\GeneratedHostname;
use App\Support\Hostname;

/**
 * Picks the generated hostname of a web service in an environment
 * (docs/05 §5): `*.localhost` in local dev, the platform wildcard when one is
 * configured (instance settings `apps_domain`), else sslip.io on the team's
 * first edge server's public IPv4. Null when none of these is available.
 */
final class GeneratedDomains
{
    public function hostnameFor(Service $service, Environment $environment, Project $project): ?string
    {
        $hostname = $this->candidate($service->slug, $environment->slug, $project);

        if ($hostname === null || ! Hostname::isValid($hostname)) {
            return null;
        }

        // Hostnames are unique per install: disambiguate projects with the
        // same slug in different teams.
        if (Domain::query()->where('hostname', $hostname)->exists()) {
            $hostname = $this->candidate($service->slug, $environment->slug, $project, substr(strtolower(substr($project->public_id, 4)), -6));
        }

        return $hostname !== null && Hostname::isValid($hostname) ? $hostname : null;
    }

    /**
     * A generated hostname whose first label is `<svc>-<qualifier>` instead
     * of `<svc>-<env>`: immutable per-deployment URLs (`web-01jb7z6x`) and
     * preview branch aliases (`web-git-feature-login`), docs/05 §5. Not
     * checked against existing domains (callers own uniqueness).
     */
    public function hostnameWith(Service $service, string $qualifier, Project $project): ?string
    {
        $hostname = $this->candidate($service->slug, $qualifier, $project);

        return $hostname !== null && Hostname::isValid($hostname) ? $hostname : null;
    }

    private function candidate(string $service, string $environment, Project $project, string $suffix = ''): ?string
    {
        $projectLabel = $suffix === '' ? $project->slug : Hostname::label($project->slug, $suffix);

        if (config()->boolean('launchroom.domains.localhost')) {
            return GeneratedHostname::localhost($service, $environment, $projectLabel);
        }

        $appsDomain = InstanceSettings::current()->apps_domain;

        if (is_string($appsDomain) && $appsDomain !== '') {
            return GeneratedHostname::wildcard($service, $environment, $projectLabel, $appsDomain);
        }

        $ip = Server::query()
            ->where('team_id', $project->team_id)
            ->where('status', ServerStatus::Online->value)
            ->whereJsonContains('roles', ServerRole::Edge->value)
            ->whereNotNull('public_ipv4')
            ->orderBy('id')
            ->value('public_ipv4');

        return is_string($ip) ? GeneratedHostname::sslip($service, $environment, $projectLabel, $ip) : null;
    }
}
