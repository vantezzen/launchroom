<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Account\UpdateProfile;
use App\Actions\Teams\DeleteAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Http\Toast;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

final class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => Features::enabled(Features::emailVerification()),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function update(ProfileUpdateRequest $request, #[CurrentUser] User $user, UpdateProfile $update): RedirectResponse
    {
        $update->handle($user, $request->safe()->string('name')->toString(), $request->safe()->string('email')->toString(), $this->auditActor($request, $user));

        Toast::success(__('Profile updated.'));

        return to_route('profile.edit');
    }

    public function destroy(ProfileDeleteRequest $request, #[CurrentUser] User $user, DeleteAccount $deleteAccount): RedirectResponse
    {
        // Refuses for the last owner of a team or the only instance owner (review L2).
        $deleteAccount->handle($user);

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
