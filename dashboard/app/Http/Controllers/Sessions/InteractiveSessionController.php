<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sessions;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Sessions\AuthorizeSessionChannel;
use App\Actions\Sessions\EndSession;
use App\Actions\Sessions\SubmitSessionCommand;
use App\Data\Audit\AuditEntryData;
use App\Data\Sessions\AuthorizeSessionData;
use App\Data\Sessions\SessionCommandData;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\LogChunk;
use App\Models\Sessions\InteractiveSession;
use App\Models\User;
use App\Queries\Sessions\SessionTabs;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * One interactive session (docs/03 §11): join its channel with the ticket,
 * send Data commands, end it, read its recording.
 */
final class InteractiveSessionController extends Controller
{
    /** Transcript lines returned at most (≈ 1 MiB of output). */
    private const int RECORDING_LINES = 20000;

    public function show(InteractiveSession $interactiveSession, #[CurrentUser] User $user): JsonResponse
    {
        Gate::authorize('view', $interactiveSession);
        $interactiveSession->loadMissing(['user:id,name', 'server:id,name']);

        return response()->json(SessionTabs::item($interactiveSession, $user))->header('Cache-Control', 'no-store, private');
    }

    /**
     * Pusher channel authorization for the browser (single-use ticket).
     */
    public function authorizeChannel(InteractiveSession $interactiveSession, AuthorizeSessionData $data, #[CurrentUser] User $user, AuthorizeSessionChannel $authorize): JsonResponse
    {
        Gate::authorize('use', $interactiveSession);

        return response()
            ->json(['auth' => $authorize->handle($user, $interactiveSession, $data)])
            ->header('Cache-Control', 'no-store, private');
    }

    public function command(Request $request, InteractiveSession $interactiveSession, SessionCommandData $data, #[CurrentUser] User $user, SubmitSessionCommand $submit): JsonResponse
    {
        Gate::authorize('use', $interactiveSession);

        return response()->json(['op' => $submit->handle($user, $interactiveSession, $data, $this->auditActor($request, $user))], 202);
    }

    public function end(InteractiveSession $interactiveSession, #[CurrentUser] User $user, EndSession $end): Response
    {
        Gate::authorize('end', $interactiveSession);

        $end->handle($interactiveSession, $user, $interactiveSession->user_id === $user->id ? 'Closed.' : 'Ended by an admin.');

        return response()->noContent();
    }

    public function recording(Request $request, InteractiveSession $interactiveSession, #[CurrentUser] User $user, RecordAuditEvent $audit): JsonResponse
    {
        Gate::authorize('viewRecording', $interactiveSession);

        $lines = [];

        if ($interactiveSession->daemon_task_id !== null) {
            LogChunk::query()
                ->where('team_id', $interactiveSession->team_id)
                ->where('daemon_task_id', $interactiveSession->daemon_task_id)
                ->orderBy('seq')
                ->lazy(200)
                ->each(function (LogChunk $chunk) use (&$lines): bool {
                    foreach ($chunk->lines as $line) {
                        $lines[] = $line['text'];

                        if (count($lines) >= self::RECORDING_LINES) {
                            return false;
                        }
                    }

                    return true;
                });
        }

        $audit->handle(new AuditEntryData(
            action: AuditAction::SessionRecordingViewed,
            actor: $this->auditActor($request, $user),
            teamId: $interactiveSession->team_id,
            subjectType: 'session',
            subjectId: $interactiveSession->public_id,
        ));

        return response()->json(['lines' => $lines])->header('Cache-Control', 'no-store, private');
    }
}
