import { Head } from '@inertiajs/react';
import { Page } from '@/components/patterns/page';
import { BackupsOverview } from '@/features/backups/backups-overview';
import type { BackupsPage } from '@/features/backups/page-types';
import { index } from '@/routes/backups';

/**
 * Backups (sidebar; docs/14 §3, docs/design/flows.md §14): what is
 * protected, what runs now, where backups go and the recovery kit.
 * Restoring stays on each service's Backups tab.
 */
export default function BackupsIndex({ page }: { page: BackupsPage }) {
    return (
        <>
            <Head title="Backups" />
            <Page size="wide">
                <BackupsOverview page={page} />
            </Page>
        </>
    );
}

BackupsIndex.layout = {
    breadcrumbs: [{ title: 'Backups', href: index() }],
};
