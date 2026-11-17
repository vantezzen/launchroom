<?php

declare(strict_types=1);

namespace App\Actions\Registry;

use App\Data\Protocol\V1\Tasks\BuildSpec;
use App\Data\Protocol\V1\Tasks\InventorySpec;
use App\Data\Protocol\V1\Tasks\RunOnceSpec;
use App\Data\Protocol\V1\Tasks\TaskSpec;
use App\Data\Registry\RegistryScope;
use App\Data\Registry\RegistryToken;
use App\Data\Registry\RegistryTokenRequest;
use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Exceptions\DomainRuleViolation;
use App\Models\DaemonTask;
use App\Models\Server;
use App\Services\Registry\RegistryTokenIssuer;
use App\Support\Images\ImageReference;
use App\Support\Images\RegistryLayout;
use App\Support\Protocol\Grammar;
use App\Support\PublicId;
use InvalidArgumentException;
use Spatie\LaravelData\Optional;

/**
 * Issues a short-lived registry token to a daemon (POST /daemon/v1/registry-token,
 * docs/05 §6, docs/09 R24). All requested scopes are granted or the request is
 * refused; nothing is ever widened:
 *
 * - pull on `mirror/<registry>/…` (the on-demand upstream mirror of public
 *   images) for any approved server;
 * - pull on a team mirror `mirror/team_<team>/<registry>/…` (private images
 *   the dashboard copied with the team's sign-in details, D91) only for a
 *   server of that team, and only when an image of that repository is in
 *   its current NodeState or in the spec of a run_once or inventory task it
 *   currently holds (`task`). Never push, never another team's path;
 * - pull on `apps/<service>` when an image of that repository is in the server's
 *   current NodeState;
 * - pull and push on `apps/<service>` and `cache/<service>` only with `task`
 *   naming a build task currently leased by this server for that service;
 * - pull on `apps/<service>` with `task` naming a run_once task (release or
 *   seed command) currently leased by this server for that service;
 * - pull on `apps/<service>` with `task` naming an inventory task currently
 *   leased by this server for that service (docs/03 §13.1).
 */
final readonly class IssueDaemonRegistryToken
{
    public function __construct(
        private RegistryTokenIssuer $issuer,
    ) {}

    public function handle(Server $server, RegistryTokenRequest $request): RegistryToken
    {
        $buildService = $request->task instanceof Optional ? null : $this->leasedTaskService($server, $request->task, DaemonTaskType::Build);
        $runOnceService = $request->task instanceof Optional ? null : $this->leasedTaskService($server, $request->task, DaemonTaskType::RunOnce)
            ?? $this->leasedTaskService($server, $request->task, DaemonTaskType::Inventory);
        $taskImage = $request->task instanceof Optional ? null : $this->leasedTaskImageRepository($server, $request->task);
        $nodeRepositories = null;
        $scopes = [];

        foreach ($request->scopes as $scope) {
            foreach ($scope->actions as $action) {
                $nodeRepositories ??= $this->nodeStateRepositories($server);

                if (! $this->allows($server, $scope->repository, $action, $buildService, $runOnceService, $taskImage, $nodeRepositories)) {
                    throw DomainRuleViolation::forbidden("Registry {$action} access to {$scope->repository} is not allowed for this server.");
                }
            }

            $scopes[] = new RegistryScope($scope->repository, array_values(array_unique($scope->actions)));
        }

        $issued = $this->issuer->issue($server->public_id, $scopes);

        return new RegistryToken($issued->token, $issued->expiresAt, $scopes);
    }

    /**
     * @param  array<string, true>  $nodeRepositories
     */
    private function allows(Server $server, string $repository, string $action, ?string $buildService, ?string $runOnceService, ?string $taskImage, array $nodeRepositories): bool
    {
        $segments = explode('/', $repository);

        if ($segments[0] === RegistryLayout::MIRROR && str_starts_with($segments[1] ?? '', 'team_')) {
            return $this->allowsTeamMirror($server, $repository, $action, $taskImage, $nodeRepositories);
        }

        // Upstream mirror of public images: mirror/<registry host>/<path…>, read-only.
        if ($segments[0] === RegistryLayout::MIRROR) {
            return $action === RegistryScope::PULL && count($segments) >= 3 && str_contains($segments[1], '.');
        }

        if (count($segments) !== 2 || ! in_array($segments[0], [RegistryLayout::APPS, RegistryLayout::CACHE], true)) {
            return false;
        }

        // A leased build task may pull and push its own service's image and cache.
        if ($buildService !== null && $segments[1] === $buildService) {
            return true;
        }

        // A leased run_once (release or seed command) or inventory task may pull
        // its service's image before that image is in any NodeState.
        if ($runOnceService !== null && $segments[0] === RegistryLayout::APPS && $segments[1] === $runOnceService && $action === RegistryScope::PULL) {
            return true;
        }

        return $segments[0] === RegistryLayout::APPS && $action === RegistryScope::PULL && isset($nodeRepositories[$repository]);
    }

    /**
     * A team mirror (private images, D91): pull only, the server's own team,
     * and an image of exactly that repository the server runs or a task it
     * holds names. Nothing else ever matches, wildcards included.
     *
     * @param  array<string, true>  $nodeRepositories
     */
    private function allowsTeamMirror(Server $server, string $repository, string $action, ?string $taskImage, array $nodeRepositories): bool
    {
        if ($action !== RegistryScope::PULL || preg_match(Grammar::TEAM_MIRROR_REPOSITORY, $repository) !== 1) {
            return false;
        }

        $team = $server->team()->value('public_id');

        if (! is_string($team) || explode('/', $repository)[1] !== strtolower($team)) {
            return false;
        }

        return isset($nodeRepositories[$repository]) || $taskImage === $repository;
    }

    /**
     * The repository (in launchroom's registry) of the image a run_once or
     * inventory task this server currently holds runs, or null.
     */
    private function leasedTaskImageRepository(Server $server, string $taskId): ?string
    {
        foreach ([DaemonTaskType::RunOnce, DaemonTaskType::Inventory] as $type) {
            $spec = $this->leasedTaskSpec($server, $taskId, $type);

            if ($spec instanceof RunOnceSpec || $spec instanceof InventorySpec) {
                $reference = ImageReference::tryParse($spec->image);

                return $reference?->digest !== null && $reference->registry.'/' === RegistryLayout::registry() ? $reference->repository : null;
            }
        }

        return null;
    }

    /**
     * The lowercased service id of a task of $type this server currently holds, or null.
     */
    private function leasedTaskService(Server $server, string $taskId, DaemonTaskType $type): ?string
    {
        $spec = $this->leasedTaskSpec($server, $taskId, $type);

        $service = match (true) {
            $spec instanceof BuildSpec => $spec->inputs->service,
            $spec instanceof RunOnceSpec, $spec instanceof InventorySpec => $spec->service,
            default => null,
        };

        return $service !== null && PublicId::isValid($service, 'svc') ? strtolower($service) : null;
    }

    /**
     * The spec of a task of $type this server currently holds under a live lease, or null.
     */
    private function leasedTaskSpec(Server $server, string $taskId, DaemonTaskType $type): ?TaskSpec
    {
        $task = DaemonTask::query()
            ->where('public_id', $taskId)
            ->where('server_id', $server->id)
            ->where('type', $type->value)
            ->where('status', DaemonTaskStatus::Leased->value)
            ->first();

        if (! $task instanceof DaemonTask
            || $task->lease_expires_at === null || $task->lease_expires_at->isPast()
            || ($task->deadline_at !== null && $task->deadline_at->isPast())) {
            return null;
        }

        try {
            return $task->taskSpec();
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Repositories of the images in the server's latest NodeState (the images
     * it may pull), e.g. `apps/svc_01j…`.
     *
     * @return array<string, true>
     */
    private function nodeStateRepositories(Server $server): array
    {
        $images = $server->latestNodeState()->first()?->facts->images ?? [];
        $repositories = [];

        foreach ($images as $image) {
            $reference = ImageReference::tryParse($image);

            if ($reference?->digest !== null && $reference->registry.'/' === RegistryLayout::registry()) {
                $repositories[$reference->repository] = true;
            }
        }

        return $repositories;
    }
}
