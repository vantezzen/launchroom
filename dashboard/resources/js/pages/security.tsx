import { Head } from '@inertiajs/react';
import { Page } from '@/components/patterns/page';
import { SecurityOverview } from '@/features/security/security-overview';
import { index } from '@/routes/security';

/**
 * Security (sidebar; docs/22 §9): inventory, advisories, the web firewall
 * and investigations of every project of the team, or of one project or
 * service (`?project=`, `?service=`).
 */
export default function SecurityPage({
    inventoryQuery,
    service,
    project,
}: {
    team: { name: string };
    inventoryQuery: string | null;
    service: string | null;
    project: string | null;
}) {
    return (
        <>
            <Head title="Security" />
            <Page size="wide">
                <SecurityOverview
                    inventoryQuery={inventoryQuery}
                    service={service}
                    project={project}
                />
            </Page>
        </>
    );
}

SecurityPage.layout = {
    breadcrumbs: [{ title: 'Security', href: index() }],
};
