<?php

declare(strict_types=1);

namespace App\Enums\Git;

/**
 * How launchroom authenticates to a git connection (docs/19 §4).
 */
enum GitAuthMethod: string
{
    /** OAuth application with PKCE; the access token is refreshed. */
    case OAuth = 'oauth';
    /** A pasted access token (personal, group, project or Gitea token). */
    case Token = 'token';
    /** Plain git over SSH with a dashboard-generated ed25519 deploy key. */
    case DeployKey = 'deploy_key';
    /** Plain git over HTTPS without credentials (public repositories). */
    case None = 'none';
}
