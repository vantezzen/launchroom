<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\ApiToken;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\User;
use App\Models\Variable;
use Illuminate\Auth\AuthenticationException;

/**
 * Route parameters are bound one by one through the team-scoped bindings,
 * narrowed to the token's team (docs/16 §1.1); these checks make sure
 * nested ones also belong together, otherwise 404.
 */
trait ScopesApiRoutes
{
    protected function token(User $user): ApiToken
    {
        return $user->currentApiToken() ?? throw new AuthenticationException;
    }

    protected function team(User $user): Team
    {
        $token = $this->token($user);
        $token->loadMissing('team');

        return $token->team;
    }

    protected function instance(Environment $environment, Service $service): ServiceInstance
    {
        abort_unless($service->project_id === $environment->project_id, 404);

        $instance = ServiceInstance::query()
            ->current()
            ->where('environment_id', $environment->id)
            ->where('service_id', $service->id)
            ->firstOrFail();

        $instance->setRelation('environment', $environment)->setRelation('service', $service);

        return $instance;
    }

    protected function variableIn(Environment $environment, Variable $variable): void
    {
        abort_unless($variable->project_id === $environment->project_id, 404);
    }

    protected function domainIn(Environment $environment, Domain $domain): void
    {
        $domain->loadMissing('serviceInstance');
        abort_unless($domain->serviceInstance->environment_id === $environment->id, 404);
    }
}
