<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Domains\AddDomain;
use App\Actions\Domains\RemoveDomain;
use App\Data\Api\CreateDomainInput;
use App\Data\Api\DomainResource;
use App\Enums\DomainKind;
use App\Http\Api\ApiOperation;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Jobs\Domains\VerifyDomainJob;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\Service;
use App\Models\User;
use App\Queries\Api\ApiResources;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class DomainController extends Controller
{
    use ScopesApiRoutes;

    #[ApiOperation(id: 'listDomains', summary: 'Domains of a service in an environment', tag: 'Domains', response: DomainResource::class, list: true)]
    public function index(Environment $environment, Service $service, ApiResources $resources): JsonResponse
    {
        $instance = $this->instance($environment, $service);
        Gate::authorize('view', $instance);

        $domains = Domain::query()
            ->where('service_instance_id', $instance->id)
            ->where('kind', '!=', DomainKind::Deployment->value)
            ->orderBy('hostname')
            ->get();

        return ApiResponse::list($domains->map($resources->domain(...)));
    }

    #[ApiOperation(id: 'createDomain', summary: 'Add a custom domain', tag: 'Domains', response: DomainResource::class, request: CreateDomainInput::class, status: 201, description: 'Point its DNS at your server; the DNS check runs right away and periodically, and it goes live with a certificate.')]
    public function store(Request $request, Environment $environment, Service $service, CreateDomainInput $input, #[CurrentUser] User $user, AddDomain $addDomain, ApiResources $resources): JsonResponse
    {
        $instance = $this->instance($environment, $service);
        Gate::authorize('create', [Domain::class, $instance]);

        $domain = $addDomain->handle($instance, $input->hostname, $this->auditActor($request, $user), $input->redirectTo);
        dispatch(new VerifyDomainJob($domain->id));

        return ApiResponse::item($resources->domain($domain->refresh()), 201);
    }

    #[ApiOperation(id: 'deleteDomain', summary: 'Remove a domain (stops routing right away)', tag: 'Domains', status: 204)]
    public function destroy(Request $request, Environment $environment, Domain $domain, #[CurrentUser] User $user, RemoveDomain $removeDomain): Response
    {
        $this->domainIn($environment, $domain);
        Gate::authorize('delete', $domain);

        $removeDomain->handle($domain, $this->auditActor($request, $user));

        return ApiResponse::noContent();
    }
}
