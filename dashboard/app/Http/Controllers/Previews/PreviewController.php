<?php

declare(strict_types=1);

namespace App\Http\Controllers\Previews;

use App\Actions\Previews\ApprovePreview;
use App\Actions\Previews\CreateShareLink;
use App\Actions\Previews\RevokeShareLink;
use App\Data\Forms\NumberRangeData;
use App\Data\Previews\ApprovePreviewInput;
use App\Data\Previews\ShareLinkInput;
use App\Http\Controllers\Controller;
use App\Http\Routing\RouteScope;
use App\Http\Toast;
use App\Models\Previews\Preview;
use App\Models\Previews\PreviewSettings;
use App\Models\Previews\PreviewShareLink;
use App\Models\Project;
use App\Models\User;
use App\Queries\Previews\PreviewList;
use App\Queries\ProjectShell;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The previews of a project (`/p/{project}/previews`, docs/06 §5): pull
 * requests with status, URLs, data strategy and expiry; fork approval and
 * share links.
 */
final class PreviewController extends Controller
{
    public function index(Project $project, PreviewList $previews, #[CurrentUser] User $user): Response
    {
        Gate::authorize('view', $project);

        $settings = PreviewSettings::forProject($project);
        $canShare = Gate::allows('share', new Preview(['team_id' => $project->team_id]));

        return Inertia::render('projects/previews', [
            ...ProjectShell::props($project, null),
            'previews' => $previews->for($project, $canShare),
            'rule' => $previews->rule($project),
            'enabled' => $settings->enabled,
            'canShare' => $canShare,
            'canApprove' => Gate::allows('approve', new Preview(['team_id' => $project->team_id, 'is_fork' => true])),
            'canManage' => Gate::allows('update', $project),
            'shareLinkDays' => NumberRangeData::of(ShareLinkInput::class, 'days'),
        ]);
    }

    public function approve(Request $request, Project $project, Preview $preview, ApprovePreviewInput $input, #[CurrentUser] User $user, ApprovePreview $approve): RedirectResponse
    {
        RouteScope::preview($project, $preview);
        Gate::authorize('approve', $preview);

        $approve->handle($preview, $input->headSha, $user, $this->auditActor($request, $user));
        Toast::success("Preview of #{$preview->pr_number} approved. It runs without secrets.");

        return back();
    }

    public function share(Request $request, Project $project, Preview $preview, ShareLinkInput $input, #[CurrentUser] User $user, CreateShareLink $create): RedirectResponse
    {
        RouteScope::preview($project, $preview);
        Gate::authorize('share', $preview);

        $create->handle($preview, $input, $user, $this->auditActor($request, $user));
        Toast::success('Share link created. Copy it from the list.');

        return back();
    }

    public function revoke(Request $request, Project $project, Preview $preview, PreviewShareLink $shareLink, #[CurrentUser] User $user, RevokeShareLink $revoke): RedirectResponse
    {
        RouteScope::shareLink(RouteScope::preview($project, $preview), $shareLink);
        Gate::authorize('delete', $shareLink);

        $revoke->handle($shareLink, $this->auditActor($request, $user));
        Toast::success('Share link revoked.');

        return back();
    }
}
