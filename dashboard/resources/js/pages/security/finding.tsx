import { Head } from '@inertiajs/react';
import { useQueryClient } from '@tanstack/react-query';
import { Page, PageHeader } from '@/components/patterns/page';
import {
    FindingDetailBody,
    FindingMenu,
    useFindingDetail,
} from '@/features/security/finding-detail';
import { index } from '@/routes/security';

type Detail = App.Data.Security.FindingDetailData;

/**
 * A finding on its own page (docs/22 §9.3), the same content as the sheet
 * on the Security page; links from notifications land here.
 */
export default function FindingPage({ detail: initial }: { detail: Detail }) {
    const client = useQueryClient();
    const { data } = useFindingDetail(initial.finding.id, initial);
    const detail = data ?? initial;
    const refresh = () =>
        void client.invalidateQueries({
            queryKey: ['security-finding', initial.finding.id],
        });

    return (
        <>
            <Head title={detail.finding.advisory} />
            <Page size="narrow">
                <PageHeader
                    title={detail.finding.title ?? detail.finding.advisory}
                    description={`${detail.finding.advisory} in ${detail.finding.service.name}`}
                />
                <div className="flex flex-col divide-y overflow-hidden rounded-xl border bg-card shadow-card">
                    <FindingDetailBody
                        detail={detail}
                        onChanged={refresh}
                        menu={
                            <FindingMenu detail={detail} onChanged={refresh} />
                        }
                    />
                </div>
            </Page>
        </>
    );
}

FindingPage.layout = {
    breadcrumbs: [{ title: 'Security', href: index() }],
};
