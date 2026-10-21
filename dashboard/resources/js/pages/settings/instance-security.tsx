import { Head } from '@inertiajs/react';
import { AuditLogSection } from '@/features/security/audit-log-section';
import { ClientCertificatesSection } from '@/features/security/client-certificates-section';
import { edit } from '@/routes/instance-security';
import { edit as teamSettings } from '@/routes/team';

type Props = {
    certificates: App.Data.Security.ClientCertificatesOverviewData;
    audit: App.Data.Security.AuditLogOverviewData;
};

/**
 * Settings → Security for this launchroom (D50), instance owners only:
 * server certificates (mTLS, docs/03 §4.3) and the audit log (docs/09 R34).
 */
export default function InstanceSecurity({ certificates, audit }: Props) {
    return (
        <>
            <Head title="Security" />
            <ClientCertificatesSection overview={certificates} />
            <AuditLogSection overview={audit} />
        </>
    );
}

InstanceSecurity.layout = {
    breadcrumbs: [
        { title: 'Settings', href: teamSettings() },
        { title: 'Security', href: edit() },
    ],
};
