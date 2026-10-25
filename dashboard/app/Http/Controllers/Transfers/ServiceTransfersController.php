<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transfers;

use App\Actions\Transfers\CancelImport;
use App\Actions\Transfers\CreateDownloadLink;
use App\Actions\Transfers\CreateUpload;
use App\Actions\Transfers\EndDatabasePreparation;
use App\Actions\Transfers\InspectImportTarget;
use App\Actions\Transfers\PrepareDatabaseForImport;
use App\Actions\Transfers\StartExport;
use App\Actions\Transfers\StartImport;
use App\Data\Transfers\DownloadLinkData;
use App\Data\Transfers\ImportInspectionData;
use App\Data\Transfers\Inputs\CreateTransferInput;
use App\Data\Transfers\Inputs\InspectImportInput;
use App\Data\Transfers\Inputs\StartExportInput;
use App\Data\Transfers\Inputs\StartImportInput;
use App\Data\Transfers\ServiceTransfersData;
use App\Data\Transfers\TransferData;
use App\Http\Controllers\Controller;
use App\Models\Backups\BackupRun;
use App\Models\ServiceInstance;
use App\Models\Transfers\DataExportRun;
use App\Models\Transfers\DataImportRun;
use App\Models\Transfers\DataTransfer;
use App\Models\User;
use App\Services\Transfers\ImportInspections;
use App\Services\Transfers\ServiceTransfersView;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * JSON endpoints behind the Move data section of the Backups tab
 * (resources/js/features/data-transfer, docs/14 §12.6). Bindings are
 * team-scoped; every action authorizes.
 */
final class ServiceTransfersController extends Controller
{
    public function show(ServiceInstance $serviceInstance, #[CurrentUser] User $user, ServiceTransfersView $view): ServiceTransfersData
    {
        Gate::authorize('viewAny', [BackupRun::class, $serviceInstance]);

        return $view->for($serviceInstance, $user);
    }

    public function storeTransfer(ServiceInstance $serviceInstance, CreateTransferInput $input, #[CurrentUser] User $user, CreateUpload $create): JsonResponse
    {
        Gate::authorize('create', [DataTransfer::class, $serviceInstance]);

        return response()->json(TransferData::fromTransfer($create->handle($serviceInstance, $input, $user)), 201);
    }

    public function inspect(Request $request, ServiceInstance $serviceInstance, InspectImportInput $input, #[CurrentUser] User $user, InspectImportTarget $inspect): ImportInspectionData
    {
        Gate::authorize('create', [DataImportRun::class, $serviceInstance]);

        return $inspect->handle($serviceInstance, $input, $user, $this->auditActor($request, $user));
    }

    public function inspection(ServiceInstance $serviceInstance, string $task, #[CurrentUser] User $user, ImportInspections $inspections): ImportInspectionData
    {
        Gate::authorize('create', [DataImportRun::class, $serviceInstance]);

        return $inspections->read($task, $serviceInstance, $user) ?? abort(404);
    }

    public function storeImport(Request $request, ServiceInstance $serviceInstance, StartImportInput $input, #[CurrentUser] User $user, StartImport $start, ServiceTransfersView $view): ServiceTransfersData
    {
        Gate::authorize('create', [DataImportRun::class, $serviceInstance]);

        $start->handle($serviceInstance, $input, $user, $this->auditActor($request, $user));

        return $view->for($serviceInstance, $user);
    }

    public function cancelImport(Request $request, ServiceInstance $serviceInstance, DataImportRun $import, #[CurrentUser] User $user, CancelImport $cancel, ServiceTransfersView $view): ServiceTransfersData
    {
        abort_unless($import->service_instance_id === $serviceInstance->id, 404);
        Gate::authorize('cancel', $import);

        $cancel->handle($import, $this->auditActor($request, $user));

        return $view->for($serviceInstance, $user);
    }

    /**
     * Starts a database that was never deployed alone for an import, or
     * keeps it waiting while the import sheet is open (docs/14 §12.9).
     */
    public function prepare(Request $request, ServiceInstance $serviceInstance, #[CurrentUser] User $user, PrepareDatabaseForImport $prepare, ServiceTransfersView $view): ServiceTransfersData
    {
        Gate::authorize('create', [DataImportRun::class, $serviceInstance]);

        $prepare->handle($serviceInstance, $user, $this->auditActor($request, $user));

        return $view->for($serviceInstance, $user);
    }

    /**
     * Stops it again when the sheet closes without an import.
     */
    public function unprepare(ServiceInstance $serviceInstance, #[CurrentUser] User $user, EndDatabasePreparation $end, ServiceTransfersView $view): ServiceTransfersData
    {
        Gate::authorize('create', [DataImportRun::class, $serviceInstance]);

        $end->handle($serviceInstance);

        return $view->for($serviceInstance, $user);
    }

    public function storeExport(Request $request, ServiceInstance $serviceInstance, StartExportInput $input, #[CurrentUser] User $user, StartExport $start, ServiceTransfersView $view): ServiceTransfersData
    {
        Gate::authorize('create', [DataExportRun::class, $serviceInstance]);

        $start->handle($serviceInstance, $input, $user, $this->auditActor($request, $user));

        return $view->for($serviceInstance, $user);
    }

    public function link(Request $request, ServiceInstance $serviceInstance, DataExportRun $export, #[CurrentUser] User $user, CreateDownloadLink $link): DownloadLinkData
    {
        abort_unless($export->service_instance_id === $serviceInstance->id, 404);
        Gate::authorize('download', $export);

        ['token' => $token, 'expiresAt' => $expiresAt] = $link->handle($export, $user);

        return new DownloadLinkData(route('transfers.download', $token), $expiresAt->toIso8601String());
    }
}
