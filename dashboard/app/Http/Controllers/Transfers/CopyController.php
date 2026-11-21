<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transfers;

use App\Actions\Transfers\CancelCopy;
use App\Actions\Transfers\InspectImportTarget;
use App\Actions\Transfers\StartCopy;
use App\Data\Transfers\CopyInspectionData;
use App\Data\Transfers\CopyOptionsData;
use App\Data\Transfers\ImportInspectionData;
use App\Data\Transfers\Inputs\InspectCopyInput;
use App\Data\Transfers\Inputs\InspectImportInput;
use App\Data\Transfers\Inputs\StartCopyInput;
use App\Data\Transfers\ServiceTransfersData;
use App\Enums\Transfers\DataSubject;
use App\Http\Controllers\Controller;
use App\Http\Routing\RouteScope;
use App\Models\ServiceInstance;
use App\Models\Transfers\DataCopyRun;
use App\Models\User;
use App\Queries\Transfers\CopyOptions;
use App\Queries\Transfers\ServiceTransfersView;
use App\Services\Transfers\CopyRules;
use App\Services\Transfers\ImportInspections;
use App\Services\Transfers\ImportTarget;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Copy data from another environment (D110, docs/14 §14): the sheet's
 * options and inspections, starting and cancelling a copy. The `{serviceInstance}`
 * is always the target; the source is the same service in the environment
 * the request names.
 */
final class CopyController extends Controller
{
    public function options(ServiceInstance $serviceInstance, #[CurrentUser] User $user, CopyOptions $options): CopyOptionsData
    {
        Gate::authorize('copyInto', [DataCopyRun::class, $serviceInstance]);

        return $options->for($serviceInstance, $user);
    }

    /**
     * Inspects the target (does it hold data) and, for a fresh copy of a
     * database, the source (its size). Both are read-only tasks.
     */
    public function inspect(Request $request, ServiceInstance $serviceInstance, InspectCopyInput $input, #[CurrentUser] User $user, InspectImportTarget $inspect): CopyInspectionData
    {
        Gate::authorize('copyInto', [DataCopyRun::class, $serviceInstance]);
        $actor = $this->auditActor($request, $user);
        $source = null;

        if ($input->source !== null) {
            $source = CopyRules::sourceFor($serviceInstance, $input->source);
            Gate::authorize('create', [DataCopyRun::class, $source, $serviceInstance]);
        }

        $target = $inspect->handle($serviceInstance, new InspectImportInput(volume: $input->volume), $user, $actor);
        $sourceInspection = $source instanceof ServiceInstance && ImportTarget::for($source)->subject === DataSubject::Database
            ? $inspect->handle($source, new InspectImportInput, $user, $actor)
            : null;

        return new CopyInspectionData($target, $sourceInspection);
    }

    /**
     * One inspection's result; `source` names the environment when it is the source's.
     */
    public function inspection(Request $request, ServiceInstance $serviceInstance, string $task, #[CurrentUser] User $user, ImportInspections $inspections): ImportInspectionData
    {
        Gate::authorize('copyInto', [DataCopyRun::class, $serviceInstance]);
        $environment = $request->query('source');
        $instance = $serviceInstance;

        if (is_string($environment) && $environment !== '') {
            $instance = CopyRules::sourceFor($serviceInstance, $environment);
            Gate::authorize('create', [DataCopyRun::class, $instance, $serviceInstance]);
        }

        return $inspections->read($task, $instance, $user) ?? abort(404);
    }

    public function store(Request $request, ServiceInstance $serviceInstance, StartCopyInput $input, #[CurrentUser] User $user, StartCopy $start, ServiceTransfersView $view): ServiceTransfersData
    {
        $source = CopyRules::sourceFor($serviceInstance, $input->source);
        Gate::authorize('create', [DataCopyRun::class, $source, $serviceInstance]);

        $start->handle($source, $serviceInstance, $input, $user, $this->auditActor($request, $user));

        return $view->for($serviceInstance, $user);
    }

    public function cancel(Request $request, ServiceInstance $serviceInstance, DataCopyRun $copy, #[CurrentUser] User $user, CancelCopy $cancel, ServiceTransfersView $view): ServiceTransfersData
    {
        $copy = RouteScope::copyRun($serviceInstance, $copy);
        Gate::authorize('cancel', $copy);

        $cancel->handle($copy, $this->auditActor($request, $user));

        return $view->for($serviceInstance, $user);
    }
}
