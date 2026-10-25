<?php

declare(strict_types=1);

namespace App\Actions\Registry;

use App\Data\Registry\RegistryScope;
use App\Data\Registry\RegistryToken;
use App\Data\Registry\RegistryTokenRequest;
use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Exceptions\DomainRuleViolation;
use App\Models\DaemonTask;
use App\Models\Server;
use App\Services\Registry\RegistryTokenIssuer;
use JsonException;
use Spatie\LaravelData\Optional;

/**
 * Issues a short-lived registry token to a daemon (POST /daemon/v1/registry-token,
 * docs/05 §6, docs/09 R24). All requested scopes are granted or the request is
 * refused; nothing is ever widened:
 *
 * - pull on `mirror/<registry>/…` (the on-demand upstream mirror) for any approved server;
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
        $nodeRepositories = null;
        $scopes = [];

        foreach ($request->scopes as $scope) {
            foreach ($scope->actions as $action) {
                $nodeRepositories ??= $this->nodeStateRepositories($server);

                if (! $this->allows($scope->repository, $action, $buildService, $runOnceService, $nodeRepositories)) {
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
    private function allows(string $repository, string $action, ?string $buildService, ?string $runOnceService, array $nodeRepositories): bool
    {
        $segments = explode('/', $repository);

        // Upstream mirror: mirror/<registry host>/<path…>, read-only.
        if ($segments[0] === 'mirror') {
            return $action === RegistryScope::PULL && count($segments) >= 3;
        }

        if (count($segments) !== 2 || ! in_array($segments[0], ['apps', 'cache'], true)) {
            return false;
        }

        // A leased build task may pull and push its own service's image and cache.
        if ($buildService !== null && $segments[1] === $buildService) {
            return true;
        }

        // A leased run_once task (release command) may pull its service's image
        // before that image is in any NodeState.
        if ($runOnceService !== null && $segments[0] === 'apps' && $segments[1] === $runOnceService && $action === RegistryScope::PULL) {
            return true;
        }

        return $segments[0] === 'apps' && $action === RegistryScope::PULL && isset($nodeRepositories[$repository]);
    }

    /**
     * The lowercased service id of a task of $type this server currently holds, or null.
     */
    private function leasedTaskService(Server $server, string $taskId, DaemonTaskType $type): ?string
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

        $service = $task->spec->service ?? null;

        return is_string($service) && preg_match('/^svc_[0-9A-HJKMNP-TV-Z]{26}$/D', $service) === 1 ? strtolower($service) : null;
    }

    /**
     * Repositories of the images in the server's latest NodeState (the images
     * it may pull), e.g. `apps/svc_01j…`.
     *
     * @return array<string, true>
     */
    private function nodeStateRepositories(Server $server): array
    {
        $document = $server->latestNodeState()->value('document');

        if (! is_string($document)) {
            return [];
        }

        try {
            $state = json_decode($document, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        $prefix = config()->string('launchroom.daemon.registry_address').'/';
        $repositories = [];

        foreach (is_array($state) && is_array($state['workloads'] ?? null) ? $state['workloads'] : [] as $workload) {
            $image = is_array($workload) ? ($workload['image'] ?? null) : null;

            if (is_string($image) && str_starts_with($image, $prefix) && str_contains($image, '@')) {
                $repositories[substr($image, strlen($prefix), (int) strpos($image, '@') - strlen($prefix))] = true;
            }
        }

        return $repositories;
    }
}
