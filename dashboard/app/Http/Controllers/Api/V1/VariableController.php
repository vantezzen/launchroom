<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Variables\CreateVariable;
use App\Actions\Variables\DeleteVariable;
use App\Actions\Variables\ExportEnvironment;
use App\Actions\Variables\RevealSecret;
use App\Actions\Variables\UpdateVariable;
use App\Data\Api\CreateVariableInput;
use App\Data\Api\EnvQuery;
use App\Data\Api\ResolvedEnvData;
use App\Data\Api\UpdateVariableInput;
use App\Data\Api\VariableResource;
use App\Data\Api\VariableValueData;
use App\Data\Variables\VariableInput;
use App\Data\Variables\VariableRowData;
use App\Data\Variables\VariableTargetChipData;
use App\Data\Variables\VariableTargetData;
use App\Data\Variables\VariableUpdate;
use App\Enums\VariableType;
use App\Events\CanvasChanged;
use App\Http\Api\ApiOperation;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Environment;
use App\Models\Service;
use App\Models\User;
use App\Models\Variable;
use App\Models\VariableTarget;
use App\Queries\ServiceVariablesView;
use App\Services\Variables\VariableStore;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Spatie\LaravelData\Optional;

/**
 * Variables through the API (docs/16 §2.2). Lists never carry secret
 * values; one value or a resolved env with secrets needs `secrets:read` and
 * the reveal permission, and is audited. Edits are staged like in the
 * dashboard and apply to the environment in the path only.
 */
final class VariableController extends Controller
{
    use ScopesApiRoutes;

    #[ApiOperation(id: 'listVariables', summary: 'Variables that apply to a service here (no secret values)', tag: 'Variables', response: VariableResource::class, list: true)]
    public function index(Environment $environment, Service $service, #[CurrentUser] User $user, ServiceVariablesView $view): JsonResponse
    {
        $instance = $this->instance($environment, $service);
        Gate::authorize('view', $instance);

        $rows = $view->get($environment, $service, $user)->variables;

        return ApiResponse::list(array_map($this->resource(...), $rows));
    }

    #[ApiOperation(id: 'createVariable', summary: 'Add a variable to a service (staged)', tag: 'Variables', response: VariableResource::class, request: CreateVariableInput::class, status: 201)]
    public function store(Request $request, Environment $environment, Service $service, CreateVariableInput $input, #[CurrentUser] User $user, CreateVariable $create, VariableStore $store): JsonResponse
    {
        $this->instance($environment, $service);
        $environment->loadMissing('project');
        Gate::authorize('create', [Variable::class, $environment->project]);

        $targets = $input->targets instanceof Optional ? [VariableTargetData::environment($environment->public_id)] : $input->targets;

        $variable = $create->handle($environment->project, $service, new VariableInput($input->key, $input->value, $input->type, $targets), $user, $this->auditActor($request, $user));
        broadcast(new CanvasChanged($environment->public_id, $service->public_id));

        return ApiResponse::item($this->model($variable, $store), 201);
    }

    #[ApiOperation(id: 'updateVariable', summary: 'Change a variable in this environment (staged)', tag: 'Variables', response: VariableResource::class, request: UpdateVariableInput::class)]
    public function update(Request $request, Environment $environment, Variable $variable, UpdateVariableInput $input, #[CurrentUser] User $user, UpdateVariable $action, VariableStore $store): JsonResponse
    {
        $this->variableIn($environment, $variable);
        Gate::authorize('update', $variable);

        // A secret turned into config becomes readable (docs/06 §3.1).
        if ($variable->type === VariableType::Secret && $input->type === VariableType::Config) {
            Gate::authorize('reveal', $variable);
        }

        $updated = $action->handle($variable, new VariableUpdate(
            key: $input->key,
            value: $input->value,
            type: $input->type,
            onlyEnvironment: $environment->public_id,
        ), $user, $this->auditActor($request, $user));
        broadcast(new CanvasChanged($environment->public_id));

        return ApiResponse::item($this->model($updated, $store));
    }

    #[ApiOperation(id: 'deleteVariable', summary: 'Remove a variable (staged)', tag: 'Variables', status: 204)]
    public function destroy(Request $request, Environment $environment, Variable $variable, #[CurrentUser] User $user, DeleteVariable $delete): Response
    {
        $this->variableIn($environment, $variable);
        Gate::authorize('delete', $variable);

        $delete->handle($variable, $this->auditActor($request, $user));
        broadcast(new CanvasChanged($environment->public_id));

        return ApiResponse::noContent();
    }

    #[ApiOperation(id: 'getVariableValue', summary: 'One variable\'s value', tag: 'Variables', response: VariableValueData::class, description: 'Secret values need the `secrets:read` ability and the reveal permission; every secret read is audited.')]
    public function value(Request $request, Environment $environment, Variable $variable, #[CurrentUser] User $user, RevealSecret $reveal): JsonResponse
    {
        $this->variableIn($environment, $variable);
        Gate::authorize('reveal', $variable);

        return ApiResponse::item(new VariableValueData($variable->key, $reveal->handle($variable, $this->auditActor($request, $user))))
            ->header('Cache-Control', 'no-store, private');
    }

    #[ApiOperation(id: 'getServiceEnv', summary: 'The resolved env of a service (env pull, run)', tag: 'Variables', response: ResolvedEnvData::class, query: EnvQuery::class, description: 'Resolved like a deploy from the last deployed revision. With `secrets=1`, secrets are included when the token has `secrets:read` and the role may reveal them; otherwise their keys are in `withheld`. Audited.')]
    public function env(Request $request, Environment $environment, Service $service, EnvQuery $query, #[CurrentUser] User $user, ExportEnvironment $export): JsonResponse
    {
        $instance = $this->instance($environment, $service);
        Gate::authorize('view', $instance);

        return ApiResponse::item($export->handle($environment, $service, $user, $query->secrets, $this->auditActor($request, $user)))
            ->header('Cache-Control', 'no-store, private');
    }

    private function resource(VariableRowData $row): VariableResource
    {
        return new VariableResource(
            id: $row->id,
            key: $row->key,
            type: $row->type,
            value: $row->type === VariableType::Secret ? null : $row->value,
            targets: array_map(static fn (VariableTargetChipData $chip): VariableTargetData => new VariableTargetData($chip->environmentId, $chip->allPreviews, $chip->branchPattern), $row->targets),
            connection: $row->connection !== null,
            editable: $row->canEdit,
        );
    }

    private function model(Variable $variable, VariableStore $store): VariableResource
    {
        $variable->loadMissing(['targets.environment:id,public_id', 'project']);
        $isSecret = $variable->type === VariableType::Secret;

        return new VariableResource(
            id: $variable->public_id,
            key: $variable->key,
            type: $variable->type,
            value: $isSecret ? null : $store->plaintext($variable->project, $variable),
            targets: array_values($variable->targets->map(static fn (VariableTarget $target): VariableTargetData => new VariableTargetData($target->environment?->public_id, $target->all_previews, $target->branch_pattern))->all()),
            connection: $variable->connection_id !== null,
            editable: Gate::allows('update', $variable),
        );
    }
}
