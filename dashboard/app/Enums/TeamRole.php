<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Attributes\SharedFacts;

/**
 * Team membership roles and the role matrix (docs/09 R9). This is the only
 * place that maps roles to permissions.
 */
#[SharedFacts('summary', 'takesGrants')]
enum TeamRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Developer = 'developer';
    case Viewer = 'viewer';

    public function can(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /**
     * Whether a member with this role may grant (or change someone to, or
     * manage someone holding) $role. Never above one's own role (docs/09 R9).
     */
    public function canGrant(self $role): bool
    {
        return $this->can(Permission::ManageMembers) && $role->rank() <= $this->rank();
    }

    /**
     * Whether members with this role can be granted $permission individually
     * (developers only: owners and admins have them, viewers never).
     */
    public function acceptsGrant(Permission $permission): bool
    {
        return $this->takesGrants() && $permission->isGrantable();
    }

    public function rank(): int
    {
        return match ($this) {
            self::Owner => 40,
            self::Admin => 30,
            self::Developer => 20,
            self::Viewer => 10,
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** What the role can do, in one line (docs/09 §2). */
    public function summary(): string
    {
        return match ($this) {
            self::Owner => 'Full access, including team settings',
            self::Admin => 'Manages servers, members, backups and projects',
            self::Developer => 'Deploys and edits projects',
            self::Viewer => 'Can view everything but not change it',
        };
    }

    /** Console and Data access are granted per member of this role (docs/09 R33). */
    public function takesGrants(): bool
    {
        return $this === self::Developer;
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => Permission::cases(),
            self::Admin => [
                Permission::ManageServers,
                Permission::ManageMembers,
                Permission::ManageProjects,
                Permission::Deploy,
                Permission::WriteVariables,
                Permission::RevealSecrets,
                Permission::ExecContainers,
                Permission::QueryDatabases,
                Permission::ManageExposure,
                Permission::View,
                Permission::ManageBackups,
                Permission::ManageAlerts,
            ],
            // Console and Data access are granted per developer
            // (Permission::grantable(), docs/09 R33); the default is no.
            self::Developer => [
                Permission::ManageProjects,
                Permission::Deploy,
                Permission::WriteVariables,
                Permission::View,
            ],
            self::Viewer => [
                Permission::View,
            ],
        };
    }
}
