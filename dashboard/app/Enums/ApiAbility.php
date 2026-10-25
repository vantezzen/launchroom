<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What an API token may do (docs/16 §1.2). The single list: routes declare
 * one (RequireTokenAbility) and {@see self::grants()} narrows the owner's
 * role inside User::hasTeamPermission(), so a token never exceeds its owner
 * and never reaches a permission no ability maps to (servers, members, team,
 * exec, exposure; backups only through the opt-in `data:transfer` on the
 * transfer endpoints). Values are stored on tokens: never rename one.
 */
enum ApiAbility: string
{
    case Read = 'read';
    case Deploy = 'deploy';
    case EnvRead = 'env:read';
    case EnvWrite = 'env:write';
    case SecretsRead = 'secrets:read';
    case DomainsWrite = 'domains:write';
    case DataTransfer = 'data:transfer';

    /**
     * The team permission this ability lets a token exercise (∩ the role),
     * or null for abilities that only gate API routes (env:read).
     */
    public function grants(): ?Permission
    {
        return match ($this) {
            self::Read => Permission::View,
            self::Deploy => Permission::Deploy,
            self::EnvRead => null,
            self::EnvWrite => Permission::WriteVariables,
            self::SecretsRead => Permission::RevealSecrets,
            // Only the domain endpoints are routed with it (docs/16 §1.2).
            self::DomainsWrite => Permission::ManageProjects,
            // Only the transfer, import and export endpoints are routed with it (docs/14 §12.7).
            self::DataTransfer => Permission::ManageBackups,
        };
    }

    /**
     * Whether a token holding $abilities may exercise $permission.
     *
     * @param  list<string>  $abilities
     */
    public static function allows(array $abilities, Permission $permission): bool
    {
        return array_any(self::cases(), fn (ApiAbility $ability): bool => $ability->grants() === $permission && in_array($ability->value, $abilities, true));
    }

    public function label(): string
    {
        return match ($this) {
            self::Read => 'Read',
            self::Deploy => 'Deploy',
            self::EnvRead => 'Read variables',
            self::EnvWrite => 'Change variables',
            self::SecretsRead => 'Read secret values',
            self::DomainsWrite => 'Manage domains',
            self::DataTransfer => 'Import and export data',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Read => 'See projects, services, deployments, logs and servers.',
            self::Deploy => 'Deploy, redeploy, roll back and cancel deployments.',
            self::EnvRead => 'See variable names and config values, and pull them without secrets.',
            self::EnvWrite => 'Add, change and remove variables (staged until you deploy).',
            self::SecretsRead => 'Read secret values, for env pull and run. Every read is audited.',
            self::DomainsWrite => 'Add and remove custom domains.',
            self::DataTransfer => 'Import dumps and archives, download database and volume data. Every transfer is audited.',
        };
    }

    /**
     * The defaults offered for new tokens and CLI sign-ins.
     *
     * @return list<self>
     */
    public static function defaults(): array
    {
        return [self::Read, self::Deploy, self::EnvRead, self::EnvWrite];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $ability): string => $ability->value, self::cases());
    }
}
