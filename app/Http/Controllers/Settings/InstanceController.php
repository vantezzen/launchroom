<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Settings\InstanceSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InstanceController extends Controller
{
    /**
     * Show the instance settings page.
     */
    public function edit(InstanceSettings $settings): Response
    {
        return Inertia::render('settings/instance', [
            'instanceSettings' => [
                'name' => $settings->name,
                'domain' => $settings->domain,
                'publicly_accessible' => $settings->publicly_accessible,
                'ipv4' => $settings->ipv4,
                'ipv6' => $settings->ipv6,
                'admin_email' => $settings->admin_email,
            ],
        ]);
    }

    /**
     * Update the instance settings.
     */
    public function update(Request $request, InstanceSettings $settings): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'domain' => ['nullable', 'string', 'max:255'],
            'publicly_accessible' => ['required', 'boolean'],
            'ipv4' => ['required', 'string', 'ip'],
            'ipv6' => ['nullable', 'string', 'ip'],
            'admin_email' => ['required', 'string', 'email', 'max:255'],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        $validated = $validator->validated();

        $settings->name = $validated['name'];
        $settings->domain = $validated['domain'];
        $settings->publicly_accessible = $validated['publicly_accessible'];
        $settings->ipv4 = $validated['ipv4'];
        $settings->ipv6 = $validated['ipv6'];
        $settings->admin_email = $validated['admin_email'];
        $settings->save();

        return back()->with('status', 'instance-settings-updated');
    }
}
