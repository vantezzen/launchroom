import { Head } from '@inertiajs/react';
import { Page } from '@/components/patterns/page';
import { SecurityOverview } from '@/features/security/security-overview';
import { index } from '@/routes/security';

/**
 * Security (sidebar; docs/22 §9): inventory, advisories, the web firewall
 * and investigations of every project of the team.
 */
export default function SecurityPage({
    inventoryQuery,
    service,
}: {
    team: { name: string };
    inventoryQuery: string | null;
    service: string | null;
}) {
    return (
        <>
            <Head title="Security" />
            <Page size="wide">
                <SecurityOverview
                    inventoryQuery={inventoryQuery}
                    service={service}
                />
            </Page>
        </>
    );
}

SecurityPage.layout = {
    breadcrumbs: [{ title: 'Security', href: index() }],
};
