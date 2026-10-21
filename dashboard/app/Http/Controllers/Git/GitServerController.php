<?php

declare(strict_types=1);

namespace App\Http\Controllers\Git;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Git\AddGitServer;
use App\Actions\Git\ConfirmGitServer;
use App\Actions\Git\RecheckGitServerHostKeys;
use App\Data\Audit\AuditEntryData;
use App\Data\Git\AddGitServerData;
use App\Enums\AuditAction;
use App\Enums\Git\GitProviderKind;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Teams\TeamSettingsController;
use App\Models\Git\GitConnection;
use App\Models\User;
use App\Services\Git\GitConnectionSecrets;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * "Any git server" (docs/19 §4.2): add a repository URL (host keys are read,
 * a deploy key generated), confirm the fingerprints and check access, check
 * the host key again later.
 */
final class GitServerController extends Controller
{
    public function store(Request $request, AddGitServerData $data, #[CurrentUser] User $user, AddGitServer $add): RedirectResponse
    {
        $team = TeamSettingsController::currentTeam($user);
        Gate::authorize('create', [GitConnection::class, $team]);

        $connection = $add->handle($team, $user, $data, $this->auditActor($request, $user));

        if ($connection->isActive()) {
            Inertia::flash('toast', ['type' => 'success', 'message' => "Added {$connection->name}."]);
        }

        return to_route('git.edit', ['setup' => $connection->public_id]);
    }

    public function confirm(Request $request, GitConnection $gitConnection, #[CurrentUser] User $user, ConfirmGitServer $confirm): RedirectResponse
    {
        Gate::authorize('update', $gitConnection);

        $request->validate([
            'fingerprints' => ['required', 'array', 'min:1', 'max:8'],
            'fingerprints.*' => ['string', 'regex:/^SHA256:[A-Za-z0-9+\/]{43}$/D'],
        ]);

        $input = $request->input('fingerprints');
        $fingerprints = is_array($input) ? array_values(array_filter($input, is_string(...))) : [];
        $confirm->handle($gitConnection, $fingerprints, $this->auditActor($request, $user));

        Inertia::flash('toast', ['type' => 'success', 'message' => "launchroom can read {$gitConnection->name}."]);

        return to_route('git.edit');
    }

    /**
     * The optional "poll now" webhook of a plain git server (docs/19 §4.2):
     * its URL and the HMAC secret, shown on request and audited.
     */
    public function webhook(Request $request, GitConnection $gitConnection, #[CurrentUser] User $user, GitConnectionSecrets $secrets, RecordAuditEvent $audit): JsonResponse
    {
        Gate::authorize('update', $gitConnection);
        abort_if($gitConnection->provider !== GitProviderKind::Git, 404);

        $audit->handle(new AuditEntryData(AuditAction::GitWebhookSecretRevealed, $this->auditActor($request, $user), $gitConnection->team_id, 'git_connection', $gitConnection->public_id));

        return new JsonResponse([
            'url' => route('git.webhooks.git', ['connection' => $gitConnection->public_id]),
            'header' => 'X-Launchroom-Signature',
            'secret' => $secrets->webhookSecret($gitConnection),
        ]);
    }

    public function recheck(GitConnection $gitConnection, RecheckGitServerHostKeys $recheck): RedirectResponse
    {
        Gate::authorize('update', $gitConnection);

        $changed = $recheck->handle($gitConnection);

        Inertia::flash('toast', $changed
            ? ['type' => 'warning', 'message' => "The host key of {$gitConnection->host()} changed. Check the new fingerprint before builds continue."]
            : ['type' => 'success', 'message' => 'The host key is unchanged.']);

        return to_route('git.edit', $changed ? ['setup' => $gitConnection->public_id] : []);
    }
}
