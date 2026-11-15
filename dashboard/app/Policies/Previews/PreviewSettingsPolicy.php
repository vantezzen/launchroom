<?php

declare(strict_types=1);

namespace App\Policies\Previews;

use App\Enums\Permission;
use App\Models\Previews\PreviewSettings;
use App\Models\User;

final class PreviewSettingsPolicy
{
    /**
     * Offer production databases as clone sources for previews: it copies
     * production data, possibly personal data, into previews, so only those
     * who manage backups (owners and admins).
     */
    public function allowProductionClone(User $user, PreviewSettings $settings): bool
    {
        return $user->hasTeamPermission($settings->team_id, Permission::ManageBackups);
    }
}
