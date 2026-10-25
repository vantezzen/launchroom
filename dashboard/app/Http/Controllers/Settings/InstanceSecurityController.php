<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Queries\Security\AdvisorySources;
use App\Queries\Security\AuditLogOverview;
use App\Queries\Security\ClientCertificatesOverview;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Security for this launchroom (D50), instance owners only:
 * client certificates for servers (docs/03 §4.3) and the audit log's
 * verification and forwarding (docs/09 R34), and the vulnerability data
 * sources (docs/22 §9.8). The sections' actions live in
 * their own controllers.
 */
final class InstanceSecurityController extends Controller
{
    public function edit(ClientCertificatesOverview $certificates, AuditLogOverview $audit): Response
    {
        Gate::authorize('manageInstance');

        return Inertia::render('settings/instance-security', [
            'certificates' => $certificates->get(),
            'audit' => $audit->get(),
            // Vulnerability data sources of the security center (docs/22 §9.8).
            'sources' => AdvisorySources::all(),
        ]);
    }
}
