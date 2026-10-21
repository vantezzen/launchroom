import { Head } from '@inertiajs/react';
import {
    LaunchroomRow,
    RecoveryKitCard,
} from '@/features/backups/control-plane-section';
import type { InstanceBackups } from '@/features/backups/control-plane-section';
import { DestinationsSection } from '@/features/backups/destinations-section';
import { SchedulesSection } from '@/features/backups/schedules-section';
import { settings } from '@/routes/backups';
import { edit as teamSettings } from '@/routes/team';

type Props = {
    destinations: App.Data.Backups.BackupDestinationData[];
    schedules: App.Data.Backups.BackupScheduleData[];
    canManage: boolean;
    instance: InstanceBackups | null;
    protection: App.Data.Backups.BackupProtectionData[];
    usage: App.Data.Backups.DestinationUsageData[];
};

/**
 * Settings → Backups (docs/14 §3, docs/design/flows.md §14): where backups
 * go, what's protected over the last 30 days, and for instance owners
 * launchroom itself and the recovery kit.
 */
export default function BackupSettings({
    destinations,
    schedules,
    canManage,
    instance,
    protection,
    usage,
}: Props) {
    return (
        <>
            <Head title="Backups" />

            <DestinationsSection
                destinations={destinations}
                canManage={canManage}
                usage={usage}
                instanceDestination={instance?.destination ?? null}
            />

            <SchedulesSection
                schedules={schedules}
                protection={protection}
                leading={
                    instance !== null && (
                        <LaunchroomRow
                            instance={instance}
                            protection={protection}
                        />
                    )
                }
            />

            {instance !== null && <RecoveryKitCard instance={instance} />}
        </>
    );
}

BackupSettings.layout = {
    breadcrumbs: [
        { title: 'Settings', href: teamSettings() },
        {
            title: 'Backups',
            href: settings(),
        },
    ],
};
