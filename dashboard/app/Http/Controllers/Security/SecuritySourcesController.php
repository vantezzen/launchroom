<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Actions\Security\SetAdvisorySources;
use App\Data\Security\SecuritySourcesData;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Settings → Security → Vulnerability data (docs/22 §9.8): the public
 * advisory sources, instance owners only.
 */
final class SecuritySourcesController extends Controller
{
    public function update(Request $request, SecuritySourcesData $data, #[CurrentUser] User $user, SetAdvisorySources $set): RedirectResponse
    {
        Gate::authorize('manageInstance');
        $set->handle($data, $this->auditActor($request, $user));

        return back();
    }
}
