<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Team-level permissions (docs/09 R9). Policies ask
 * {@see TeamRole::can()} for one of these; they never compare roles directly.
 */
enum Permission: string
{
    /** Instance settings, delete team, transfer ownership. */
    case ManageTeam = 'team.manage';

    /** Add/remove servers; mesh, registry and GitHub App settings. */
    case ManageServers = 'servers.manage';

    /** Invite members and change roles (never above the actor's own role). */
    case ManageMembers = 'members.manage';

    /** Create/delete projects, apps and databases. */
    case ManageProjects = 'projects.manage';

    /** Deploy, roll back, restart. */
    case Deploy = 'deployments.create';

    /** Edit variables (write). */
    case WriteVariables = 'variables.write';

    /** Reveal secret values. */
    case RevealSecrets = 'secrets.reveal';

    /** Console: a shell inside a service's container (docs/03 §11, docs/09 R33). */
    case ExecContainers = 'containers.exec';

    /** Data tab: browse a managed database and run statements (docs/03 §11, docs/09 R33). */
    case QueryDatabases = 'databases.query';

    /** Expose databases publicly, relax container hardening. */
    case ManageExposure = 'exposure.manage';

    /** View logs, status and metrics. */
    case View = 'resources.view';

    /** Backup destinations and schedules, restore into a service, download dumps (docs/14). */
    case ManageBackups = 'backups.manage';

    /** Notification channels and alert rules (docs/15 §3.7). */
    case ManageAlerts = 'alerts.manage';

    /**
     * Permissions an owner or admin may grant to an individual developer on
     * top of their role (`team_user.permissions`, docs/09 R33).
     *
     * @return list<self>
     */
    public static function grantable(): array
    {
        return [self::ExecContainers, self::QueryDatabases];
    }

    public function isGrantable(): bool
    {
        return in_array($this, self::grantable(), true);
    }
}
