<?php

declare(strict_types=1);

namespace App\Http\Controllers\ApiTokens;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\User;
use App\Queries\Api\ApiTokenOptions;
use App\Support\InstallCommands;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → AI agents (docs/16 §7.6, D112): how to connect an AI coding
 * agent through `launchroom mcp`, with the access it should get. Nothing is
 * created here: the CLI's sign-in makes the token.
 */
final class AiAgentsController extends Controller
{
    public function __invoke(#[CurrentUser] User $user, ApiTokenOptions $options): Response
    {
        Gate::authorize('viewAny', ApiToken::class);

        return Inertia::render('settings/ai-agents', [
            'teamOptions' => $options->teams($user),
            'cliInstall' => InstallCommands::cli(),
        ]);
    }
}
