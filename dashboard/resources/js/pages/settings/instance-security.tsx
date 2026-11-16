import { Head } from '@inertiajs/react';
import { AuditLogSection } from '@/features/security/audit-log-section';
import { ClientCertificatesSection } from '@/features/security/client-certificates-section';
import { VulnerabilityDataSection } from '@/features/security/vulnerability-data-section';
import SettingsLayout from '@/features/settings/settings-layout';

type Props = {
    certificates: App.Data.Security.ClientCertificatesOverviewData;
    audit: App.Data.Security.AuditLogOverviewData;
    sources: App.Data.Security.FeedStateData[];
};

/**
 * Settings → Certificates & audit for this launchroom (D50), instance owners only:
 * server certificates (mTLS, docs/03 §4.3) and the audit log (docs/09 R34).
 */
export default function InstanceSecurity({
    certificates,
    audit,
    sources,
}: Props) {
    return (
        <>
            <Head title="Certificates & audit" />
            <ClientCertificatesSection overview={certificates} />
            <AuditLogSection overview={audit} />
            <VulnerabilityDataSection sources={sources} />
        </>
    );
}

InstanceSecurity.layout = SettingsLayout;
