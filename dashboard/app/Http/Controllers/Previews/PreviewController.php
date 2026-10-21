<?php

declare(strict_types=1);

namespace App\Http\Controllers\Previews;

use App\Actions\Previews\ApprovePreview;
use App\Actions\Previews\CreateShareLink;
use App\Actions\Previews\RevokeShareLink;
use App\Data\Previews\ApprovePreviewInput;
use App\Data\Previews\ShareLinkInput;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectScope;
use App\Models\Previews\Preview;
use App\Models\Previews\PreviewSettings;
use App\Models\Previews\PreviewShareLink;
use App\Models\Project;
use App\Models\User;
use App\Queries\Previews\PreviewList;
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
            ...ProjectScope::shellProps($project, null),
            'previews' => $previews->for($project, $canShare),
            'enabled' => $settings->enabled,
            'canShare' => $canShare,
            'canApprove' => Gate::allows('approve', new Preview(['team_id' => $project->team_id, 'is_fork' => true])),
            'canManage' => Gate::allows('update', $project),
        ]);
    }

    public function approve(Request $request, Project $project, Preview $preview, ApprovePreviewInput $input, #[CurrentUser] User $user, ApprovePreview $approve): RedirectResponse
    {
        $this->scope($project, $preview);
        Gate::authorize('approve', $preview);

        $approve->handle($preview, $input->headSha, $user, $this->auditActor($request, $user));
        Inertia::flash('toast', ['type' => 'success', 'message' => "Preview of #{$preview->pr_number} approved. It runs without secrets."]);

        return back();
    }

    public function share(Request $request, Project $project, Preview $preview, ShareLinkInput $input, #[CurrentUser] User $user, CreateShareLink $create): RedirectResponse
    {
        $this->scope($project, $preview);
        Gate::authorize('share', $preview);

        $create->handle($preview, $input, $user, $this->auditActor($request, $user));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Share link created. Copy it from the list.']);

        return back();
    }

    public function revoke(Request $request, Project $project, Preview $preview, PreviewShareLink $shareLink, #[CurrentUser] User $user, RevokeShareLink $revoke): RedirectResponse
    {
        $this->scope($project, $preview);
        abort_unless($shareLink->preview_id === $preview->id, 404);
        Gate::authorize('delete', $shareLink);

        $revoke->handle($shareLink, $this->auditActor($request, $user));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Share link revoked.']);

        return back();
    }

    private function scope(Project $project, Preview $preview): void
    {
        abort_unless($preview->project_id === $project->id, 404);
    }
}
