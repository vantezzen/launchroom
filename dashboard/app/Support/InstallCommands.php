<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Every `curl … | sh` command the dashboard shows. The environment and flags
 * are the contract of deploy/install/install.sh, deploy/install/server.sh
 * and deploy/install/cli.sh.
 */
final class InstallCommands
{
    /** The control-plane installer with `--version`, `--move`, `--restore` … (docs/11 §2). */
    public static function controlPlane(string ...$flags): string
    {
        return 'curl -fsSL '.config()->string('launchroom.install.installer_url').' | sh'.($flags === [] ? '' : ' -s -- '.implode(' ', $flags));
    }

    /** The daemon installer for a new server (docs/11 §3, docs/03 §3). */
    public static function server(string $controlPlaneUrl, #[\SensitiveParameter] string $joinToken, string $controlPlaneKey): string
    {
        return "curl -fsSL {$controlPlaneUrl}/install/server.sh | LR_JOIN_TOKEN={$joinToken} LR_CP_KEY={$controlPlaneKey} LR_CONTROL_PLANE_URL={$controlPlaneUrl} sh";
    }

    /** The CLI installer (docs/16 §2). */
    public static function cli(): string
    {
        return 'curl -fsSL '.config()->string('launchroom.install.cli_installer_url').' | sh';
    }
}
