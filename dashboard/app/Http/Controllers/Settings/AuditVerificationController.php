<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Audit\RequestAuditVerification;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * "Verify now" (Settings → Security, docs/09 R34): a full check of the
 * audit log from the first entry, in the background.
 */
final class AuditVerificationController extends Controller
{
    public function __invoke(RequestAuditVerification $request): RedirectResponse
    {
        Gate::authorize('manageInstance');

        $request->handle();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Checking the audit log from the first entry. This page updates when it is done.']);

        return back();
    }
}
