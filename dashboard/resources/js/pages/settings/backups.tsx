import { Head } from '@inertiajs/react';
import { BackupsOverview } from '@/features/backups/backups-overview';
import type { BackupsPage } from '@/features/backups/page-types';
import SettingsLayout from '@/features/settings/settings-layout';

/**
 * Settings → Backups (team settings, D114; docs/14 §3, docs/design/flows.md
 * §14): what is protected, what runs now, where backups go and the
 * recovery kit. Restoring stays on each service's Backups settings.
 */
export default function BackupsSettings({ page }: { page: BackupsPage }) {
    return (
        <>
            <Head title="Backups" />
            <BackupsOverview page={page} />
        </>
    );
}

BackupsSettings.layout = SettingsLayout;
