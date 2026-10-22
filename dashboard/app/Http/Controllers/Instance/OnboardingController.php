<?php

declare(strict_types=1);

namespace App\Http\Controllers\Instance;

use App\Actions\Instance\ConfirmAppsDomain;
use App\Actions\Instance\DismissTwoFactorRecommendation;
use App\Actions\Instance\SetAppsDomain;
use App\Actions\Instance\UpdateOnboarding;
use App\Data\Git\ManifestRedirectData;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Git\GitSettingsController;
use App\Models\User;
use App\Queries\OnboardingChecklist;
use App\Services\Dns\DnsCheck;
use App\Services\Dns\EdgeAddresses;
use App\Support\Git\WebhookReachability;
use App\Support\Hostname;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The first-run checklist (docs/08 §3.1) and the platform domain with its
 * live DNS check. Instance owners only.
 */
final class OnboardingController extends Controller
{
    public function show(Request $request, #[CurrentUser] User $user, OnboardingChecklist $checklist): Response
    {
        Gate::authorize('manageInstance');

        $onboarding = $checklist->for($user);
        // "Connect GitHub" runs inline: the manifest started here is posted
        // to GitHub from this page (GitHubReturnTo), like Settings → Git does.
        $manifest = ManifestRedirectData::fromSession($request->session()->get(GitSettingsController::MANIFEST_FLASH_KEY));

        return Inertia::render('onboarding', [
            'onboarding' => $onboarding,
            'githubManifest' => $onboarding->canConnectGitHub ? $manifest : null,
            'githubWebhookUrl' => route('git.github.webhook'),
            'githubWebhookReachable' => WebhookReachability::isPublic(route('git.github.webhook')),
        ]);
    }

    /**
     * Skip / un-skip a step, dismiss / reopen the checklist.
     */
    public function update(Request $request, UpdateOnboarding $updateOnboarding): RedirectResponse
    {
        Gate::authorize('manageInstance');

        $request->validate([
            'dismissed' => ['sometimes', 'boolean'],
            'skip' => ['sometimes', 'nullable', 'string', Rule::in(OnboardingChecklist::SKIPPABLE)],
            'unskip' => ['sometimes', 'nullable', 'string', Rule::in(OnboardingChecklist::SKIPPABLE)],
        ]);

        $updateOnboarding->handle(
            $request->has('dismissed') ? $request->boolean('dismissed') : null,
            $request->filled('skip') ? $request->string('skip')->toString() : null,
            $request->filled('unskip') ? $request->string('unskip')->toString() : null,
        );

        return $request->boolean('dismissed') ? to_route('projects.index') : back();
    }

    /**
     * "Not now" on the two-factor recommendation (D33: never required).
     */
    public function dismissTwoFactor(#[CurrentUser] User $user, DismissTwoFactorRecommendation $dismiss): RedirectResponse
    {
        Gate::authorize('manageInstance');

        $dismiss->handle($user);

        return back();
    }

    /**
     * Set or clear the platform domain (`apps_domain`).
     */
    public function domain(Request $request, #[CurrentUser] User $user, SetAppsDomain $setAppsDomain): RedirectResponse
    {
        Gate::authorize('manageInstance');

        $request->validate(['appsDomain' => ['present', 'nullable', 'string', 'max:253']]);
        $domain = $request->filled('appsDomain') ? $request->string('appsDomain')->toString() : null;

        $settings = $setAppsDomain->handle($domain, $this->auditActor($request, $user));

        Inertia::flash('toast', ['type' => 'success', 'message' => $settings->apps_domain === null
            ? 'Platform domain removed. New services get sslip.io addresses.'
            : "Saved {$settings->apps_domain}. Create the DNS record below."]);

        return back();
    }

    /**
     * "Continue" / "Continue anyway" on the address step: re-checks DNS and
     * marks the step done (docs/08 §3.1).
     */
    public function confirmDomain(Request $request, ConfirmAppsDomain $confirm): RedirectResponse
    {
        Gate::authorize('manageInstance');

        $request->validate(['anyway' => ['sometimes', 'boolean']]);

        $result = $confirm->handle($request->boolean('anyway'));

        Inertia::flash('toast', $result->status === 'ok'
            ? ['type' => 'success', 'message' => 'DNS verified. Your apps get addresses under this domain.']
            : ['type' => 'success', 'message' => 'Saved. Apps are reachable once DNS reaches your server.']);

        return back();
    }

    /**
     * Live DNS check of `*.domain` against the edge servers (JSON), with an
     * HTTP reachability check when the records point elsewhere (proxies).
     */
    public function check(Request $request, DnsCheck $check, EdgeAddresses $edges): JsonResponse
    {
        Gate::authorize('manageInstance');

        $request->validate(['domain' => ['required', 'string', 'max:240']]);
        $domain = Hostname::normalize(preg_replace('/^\*\./', '', $request->string('domain')->trim()->toString()) ?? '');

        abort_unless(Hostname::isValid($domain) && ! Hostname::isLocal($domain), 422, 'Enter a domain such as apps.example.com.');

        return response()->json($check->wildcard($domain, $edges->addresses(null)))->header('Cache-Control', 'no-store');
    }
}
