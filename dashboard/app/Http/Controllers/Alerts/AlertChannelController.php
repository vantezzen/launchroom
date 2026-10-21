<?php

declare(strict_types=1);

namespace App\Http\Controllers\Alerts;

use App\Actions\Alerts\CreateAlertChannel;
use App\Actions\Alerts\DeleteAlertChannel;
use App\Actions\Alerts\SendTestNotification;
use App\Actions\Alerts\UpdateAlertChannel;
use App\Data\Alerts\AlertChannelInput;
use App\Data\Alerts\AlertChannelUpdateInput;
use App\Enums\Alerts\AlertDeliveryStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Teams\TeamSettingsController;
use App\Models\Alerts\AlertChannel;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Notification channels (docs/15 §3.5–3.6). A new webhook's signing secret
 * is flashed once (`alertSigningSecret`) and never shown again.
 */
final class AlertChannelController extends Controller
{
    public function store(Request $request, AlertChannelInput $input, #[CurrentUser] User $user, CreateAlertChannel $create): RedirectResponse
    {
        $team = TeamSettingsController::currentTeam($user);
        Gate::authorize('create', [AlertChannel::class, $team]);

        $created = $create->handle($team, $input, $user, $this->auditActor($request, $user));

        if ($created->signingSecret !== null) {
            Inertia::flash('alertSigningSecret', ['channel' => $created->channel->public_id, 'secret' => $created->signingSecret]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Added. Send a test to make sure it arrives.']);
        Inertia::flash('alertChannelCreated', $created->channel->public_id);

        return back();
    }

    public function update(Request $request, AlertChannel $alertChannel, AlertChannelUpdateInput $input, #[CurrentUser] User $user, UpdateAlertChannel $update): RedirectResponse
    {
        Gate::authorize('update', $alertChannel);

        $secret = $update->handle($alertChannel, $input, $this->auditActor($request, $user));

        if ($secret !== null) {
            Inertia::flash('alertSigningSecret', ['channel' => $alertChannel->public_id, 'secret' => $secret]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => match (true) {
            $input->enabled === false => "{$alertChannel->name} is paused.",
            $input->enabled === true => "{$alertChannel->name} gets alerts again.",
            $secret !== null => 'New signing secret ready. Update your receiver, the old one no longer works.',
            default => 'Saved.',
        }]);

        return back();
    }

    public function destroy(Request $request, AlertChannel $alertChannel, #[CurrentUser] User $user, DeleteAlertChannel $delete): RedirectResponse
    {
        Gate::authorize('delete', $alertChannel);

        $delete->handle($alertChannel, $this->auditActor($request, $user));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Removed {$alertChannel->name}."]);

        return back();
    }

    public function test(AlertChannel $alertChannel, SendTestNotification $send): RedirectResponse
    {
        Gate::authorize('test', $alertChannel);

        $delivery = $send->handle($alertChannel);

        Inertia::flash('toast', $delivery->status === AlertDeliveryStatus::Sent
            ? ['type' => 'success', 'message' => "Test sent to {$alertChannel->name}."]
            : ['type' => 'error', 'message' => $delivery->error ?? 'The test could not be sent.']);

        return back();
    }
}
