<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\ConfigAsCode\DryRunConfigFile;
use App\Actions\ConfigAsCode\ExportEnvironmentConfig;
use App\Data\Api\ConfigContentInput;
use App\Data\Api\ExportConfigQuery;
use App\Data\ConfigAsCode\ConfigCheckData;
use App\Data\ConfigAsCode\ConfigExportData;
use App\Data\ConfigAsCode\ConfigFileEnvironmentData;
use App\Http\Api\ApiOperation;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Environment;
use App\Models\EnvironmentRevision;
use App\Models\User;
use App\Queries\ConfigAsCode\ConfigFilePage;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * launchroom.json through the API (docs/18 §7): export, validate, dry-run
 * diff and the last read. The same Actions as the dashboard and pushes.
 */
final class ConfigController extends Controller
{
    #[ApiOperation(id: 'exportConfig', summary: 'The environment as launchroom.json', tag: 'Config', response: ConfigExportData::class, query: ExportConfigQuery::class, description: 'Config values verbatim, secrets as `{ "secret": true }`, generated values as their generator. `revision` exports a deployed revision instead of the current settings.')]
    public function export(Request $request, Environment $environment, ExportConfigQuery $query, #[CurrentUser] User $user, ExportEnvironmentConfig $export): JsonResponse
    {
        Gate::authorize('view', $environment);

        $revision = $query->revision === null ? null : EnvironmentRevision::query()->where('environment_id', $environment->id)->where('number', $query->revision)->firstOrFail();

        return ApiResponse::item($export->handle($environment, $revision, $this->auditActor($request, $user)));
    }

    #[ApiOperation(id: 'validateConfig', summary: 'Check a launchroom.json against an environment', tag: 'Config', response: ConfigCheckData::class, request: ConfigContentInput::class, description: 'Syntax, schema and rules, each problem with a line and column. Nothing is stored.')]
    public function check(Environment $environment, ConfigContentInput $input, DryRunConfigFile $dryRun): JsonResponse
    {
        Gate::authorize('view', $environment);

        return ApiResponse::item($dryRun->handle($environment, $input->content, diff: false));
    }

    #[ApiOperation(id: 'diffConfig', summary: 'The staged changes a launchroom.json would produce', tag: 'Config', response: ConfigCheckData::class, request: ConfigContentInput::class, description: 'A dry run: the file is applied in a transaction that is rolled back. Domains are described, not checked with DNS.')]
    public function diff(Environment $environment, ConfigContentInput $input, DryRunConfigFile $dryRun): JsonResponse
    {
        Gate::authorize('view', $environment);

        return ApiResponse::item($dryRun->handle($environment, $input->content, diff: true));
    }

    #[ApiOperation(id: 'getConfigStatus', summary: 'The last read of the environment\'s launchroom.json', tag: 'Config', response: ConfigFileEnvironmentData::class)]
    public function status(Environment $environment, ConfigFilePage $page): JsonResponse
    {
        Gate::authorize('view', $environment);
        $environment->loadMissing('project');

        $row = array_values(array_filter($page->for($environment->project)->environments, static fn (ConfigFileEnvironmentData $row): bool => $row->id === $environment->public_id))[0] ?? null;
        abort_if($row === null, 404);

        return ApiResponse::item($row);
    }
}
