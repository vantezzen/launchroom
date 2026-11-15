<?php

declare(strict_types=1);

namespace App\Http\Controllers\Git;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Git\AddGitServer;
use App\Actions\Git\ConfirmGitServer;
use App\Actions\Git\RecheckGitServerHostKeys;
use App\Data\Audit\AuditEntryData;
use App\Data\Git\AddGitServerData;
use App\Data\Git\ConfirmHostKeysData;
use App\Data\Inertia\ToastData;
use App\Enums\AuditAction;
use App\Enums\Git\GitProviderKind;
use App\Enums\ToastType;
use App\Http\Controllers\Controller;
use App\Http\Routing\CurrentTeam;
use App\Http\Toast;
use App\Models\Git\GitConnection;
use App\Models\Team;
use App\Models\User;
use App\Services\Crypto\SealedColumns;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * "Any git server" (docs/19 §4.2): add a repository URL (host keys are read,
 * a deploy key generated), confirm the fingerprints and check access, check
 * the host key again later.
 */
final class GitServerController extends Controller
{
    public function store(Request $request, AddGitServerData $data, #[CurrentUser] User $user, AddGitServer $add, #[CurrentTeam] Team $team): RedirectResponse
    {
        Gate::authorize('create', [GitConnection::class, $team]);

        $connection = $add->handle($team, $user, $data, $this->auditActor($request, $user));

        if ($connection->isActive()) {
            Toast::success("Added {$connection->name}.");
        }

        return to_route('git.edit', ['setup' => $connection->public_id]);
    }

    public function confirm(Request $request, GitConnection $gitConnection, ConfirmHostKeysData $data, #[CurrentUser] User $user, ConfirmGitServer $confirm): RedirectResponse
    {
        Gate::authorize('update', $gitConnection);

        $confirm->handle($gitConnection, $data->fingerprints, $this->auditActor($request, $user));

        Toast::success("launchroom can read {$gitConnection->name}.");

        return to_route('git.edit');
    }

    /**
     * The optional "poll now" webhook of a plain git server (docs/19 §4.2):
     * its URL and the HMAC secret, shown on request and audited.
     */
    public function webhook(Request $request, GitConnection $gitConnection, #[CurrentUser] User $user, SealedColumns $sealed, RecordAuditEvent $audit): JsonResponse
    {
        Gate::authorize('update', $gitConnection);
        abort_if($gitConnection->provider !== GitProviderKind::Git, 404);

        $audit->handle(new AuditEntryData(AuditAction::GitWebhookSecretRevealed, $this->auditActor($request, $user), $gitConnection->team_id, 'git_connection', $gitConnection->public_id));

        return new JsonResponse([
            'url' => route('git.webhooks.git', ['connection' => $gitConnection->public_id]),
            'header' => 'X-Launchroom-Signature',
            'secret' => (string) $sealed->reveal($gitConnection, 'webhook_secret'),
        ]);
    }

    public function recheck(GitConnection $gitConnection, RecheckGitServerHostKeys $recheck): RedirectResponse
    {
        Gate::authorize('update', $gitConnection);

        $changed = $recheck->handle($gitConnection);

        Toast::show($changed
            ? new ToastData(ToastType::Warning, "The host key of {$gitConnection->host()} changed. Check the new fingerprint before builds continue.")
            : new ToastData(ToastType::Success, 'The host key is unchanged.'));

        return to_route('git.edit', $changed ? ['setup' => $gitConnection->public_id] : []);
    }
}
