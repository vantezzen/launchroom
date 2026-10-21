<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Audit\CreateAuditSink;
use App\Actions\Audit\DeleteAuditSink;
use App\Actions\Audit\RetryAuditSink;
use App\Actions\Audit\TestAuditSink;
use App\Actions\Audit\UpdateAuditSink;
use App\Data\Audit\AuditSinkInput;
use App\Data\Audit\AuditSinkUpdateInput;
use App\Http\Controllers\Controller;
use App\Models\AuditSink;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Settings → Security → Audit log forwarding (docs/09 R34), instance owners
 * only. A new webhook's signing secret is flashed once
 * (`auditSigningSecret`) and never shown again.
 */
final class AuditSinkController extends Controller
{
    public function store(Request $request, AuditSinkInput $input, #[CurrentUser] User $user, CreateAuditSink $create): RedirectResponse
    {
        Gate::authorize('create', AuditSink::class);

        $created = $create->handle($input, $user, $this->auditActor($request, $user));

        if ($created->signingSecret !== null) {
            Inertia::flash('auditSigningSecret', ['sink' => $created->sink->public_id, 'secret' => $created->signingSecret]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Added {$created->sink->name}. It starts with the first entry and catches up."]);

        return back();
    }

    public function update(Request $request, AuditSink $auditSink, AuditSinkUpdateInput $input, #[CurrentUser] User $user, UpdateAuditSink $update): RedirectResponse
    {
        Gate::authorize('update', $auditSink);

        $secret = $update->handle($auditSink, $input, $this->auditActor($request, $user));

        if ($secret !== null) {
            Inertia::flash('auditSigningSecret', ['sink' => $auditSink->public_id, 'secret' => $secret]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => match (true) {
            $input->enabled === false => "{$auditSink->name} is paused. Entries wait here until you resume it.",
            $input->enabled === true => "{$auditSink->name} is catching up.",
            $secret !== null => 'New signing secret ready. Update your receiver, the old one no longer works.',
            default => 'Saved.',
        }]);

        return back();
    }

    public function destroy(Request $request, AuditSink $auditSink, #[CurrentUser] User $user, DeleteAuditSink $delete): RedirectResponse
    {
        Gate::authorize('delete', $auditSink);

        $delete->handle($auditSink, $this->auditActor($request, $user));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Removed {$auditSink->name}. What it received stays there."]);

        return back();
    }

    public function test(AuditSink $auditSink, TestAuditSink $test): RedirectResponse
    {
        Gate::authorize('test', $auditSink);

        Inertia::flash('toast', ['type' => 'success', 'message' => $test->handle($auditSink)]);

        return back();
    }

    public function retry(AuditSink $auditSink, RetryAuditSink $retry): RedirectResponse
    {
        Gate::authorize('update', $auditSink);

        $retry->handle($auditSink);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Trying {$auditSink->name} again now."]);

        return back();
    }
}
