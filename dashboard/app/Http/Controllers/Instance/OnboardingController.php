<?php

declare(strict_types=1);

namespace App\Http\Controllers\Instance;

use App\Actions\Instance\ConfirmAppsDomain;
use App\Actions\Instance\DismissFirstAppCard;
use App\Actions\Instance\DismissTwoFactorRecommendation;
use App\Actions\Instance\SetAppsDomain;
use App\Actions\Instance\UpdateOnboarding;
use App\Data\Git\ManifestRedirectData;
use App\Data\Inertia\ToastData;
use App\Data\Instance\AppsDomainData;
use App\Data\Instance\ConfirmAppsDomainData;
use App\Data\Instance\UpdateOnboardingData;
use App\Data\Instance\WildcardCheckData;
use App\Enums\Domains\DnsCheckStatus;
use App\Enums\ToastType;
use App\Http\Controllers\Controller;
use App\Http\Routing\CurrentTeam;
use App\Http\Toast;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use App\Queries\FirstApp;
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
        $manifest = ManifestRedirectData::fromSession($request->session()->get(ManifestRedirectData::SESSION_KEY));

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
    public function update(UpdateOnboardingData $data, UpdateOnboarding $updateOnboarding): RedirectResponse
    {
        Gate::authorize('manageInstance');

        $updateOnboarding->handle($data->dismissed, $data->skip, $data->unskip);

        return $data->dismissed === true ? to_route('projects.index') : back();
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
     * The first-live card on the project canvas (flows §5): the team's first
     * app while its first deploy runs and for a while after it went live,
     * until dismissed. JSON; null when there is nothing to show.
     */
    public function firstApp(FirstApp $firstApp, #[CurrentTeam] Team $team): JsonResponse
    {
        Gate::authorize('manageInstance');
        Gate::authorize('view', $team);

        $seen = InstanceSettings::current()->onboarding?->firstAppSeenAt !== null;

        return response()->json(['firstApp' => $firstApp->card($team, $seen)])->header('Cache-Control', 'no-store');
    }

    public function dismissFirstApp(DismissFirstAppCard $dismiss): RedirectResponse
    {
        Gate::authorize('manageInstance');

        $dismiss->handle();

        return back();
    }

    public function domain(Request $request, AppsDomainData $data, #[CurrentUser] User $user, SetAppsDomain $setAppsDomain): RedirectResponse
    {
        Gate::authorize('manageInstance');

        $settings = $setAppsDomain->handle($data->appsDomain, $this->auditActor($request, $user));

        Toast::success($settings->apps_domain === null
            ? 'Platform domain removed. New services get sslip.io addresses.'
            : "Saved {$settings->apps_domain}. Create the DNS record below.");

        return back();
    }

    /**
     * "Continue" / "Continue anyway" on the address step: re-checks DNS and
     * marks the step done (docs/08 §3.1).
     */
    public function confirmDomain(ConfirmAppsDomainData $data, ConfirmAppsDomain $confirm): RedirectResponse
    {
        Gate::authorize('manageInstance');

        $result = $confirm->handle($data->anyway);

        Toast::show($result->status === DnsCheckStatus::Ok
            ? new ToastData(ToastType::Success, 'DNS verified. Your apps get addresses under this domain.')
            : new ToastData(ToastType::Success, 'Saved. Apps become reachable once the DNS record points at your server.'));

        return back();
    }

    /**
     * Live DNS check of `*.domain` against the edge servers (JSON), with an
     * HTTP reachability check when the records point elsewhere (proxies).
     */
    public function check(WildcardCheckData $data, DnsCheck $check, EdgeAddresses $edges): JsonResponse
    {
        Gate::authorize('manageInstance');

        $domain = $data->hostname();

        abort_unless(Hostname::isValid($domain) && ! Hostname::isLocal($domain), 422, 'Enter a domain such as apps.example.com.');

        return response()->json($check->wildcard($domain, $edges->addresses(null)))->header('Cache-Control', 'no-store');
    }
}
