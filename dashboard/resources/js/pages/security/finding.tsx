import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { useQueryClient } from '@tanstack/react-query';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useEffect } from 'react';
import type { ReactNode } from 'react';
import { MetaLine } from '@/components/patterns/meta-line';
import { Page, PageHeader } from '@/components/patterns/page';
import { StatusIcon } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import {
    FindingActions,
    FindingDetailBody,
    useFindingDetail,
} from '@/features/security/finding-detail';
import { index } from '@/routes/security';
import { securityKeys } from '@/features/security/query-keys';
import type { BreadcrumbItem } from '@/types';

type Detail = App.Data.Security.FindingDetailData;
type Siblings = {
    previous: string | null;
    next: string | null;
    position: number | null;
    total: number;
};

function StatusWord({ detail }: { detail: Detail }) {
    const status = detail.finding.status;

    if (status === 'resolved') {
        return (
            <span className="inline-flex items-center gap-1.5">
                <StatusIcon state="active" />
                Fixed
            </span>
        );
    }

    if (status === 'dismissed') {
        return (
            <span className="inline-flex items-center gap-1.5">
                <StatusIcon state="idle" />
                Marked not affected
            </span>
        );
    }

    return (
        <span className="inline-flex items-center gap-1.5">
            <StatusIcon
                state={
                    detail.finding.level === 'action_needed'
                        ? 'failed'
                        : 'warning'
                }
            />
            Open
        </span>
    );
}

/**
 * A finding on its own page (docs/22 §9.3), the same content as the sheet
 * on the Security page; links from notifications land here. The header
 * carries the state (open, not affected, fixed; first seen; last checked),
 * the actions and previous / next across the open "Action needed" list.
 */
export default function FindingPage({
    detail: initial,
    siblings,
}: {
    detail: Detail;
    siblings: Siblings;
}) {
    const client = useQueryClient();
    const { data } = useFindingDetail(initial.finding.id, initial);
    const detail = data ?? initial;
    const refresh = () =>
        void client.invalidateQueries({
            queryKey: securityKeys.finding(initial.finding.id),
        });
    const advisory = detail.finding.advisory;

    useEffect(() => {
        setLayoutProps<{ breadcrumbs: BreadcrumbItem[] }>({
            breadcrumbs: [
                { title: 'Security', href: index() },
                { title: advisory, href: detail.finding.href },
            ],
        });
    }, [advisory, detail.finding.href]);

    return (
        <>
            <Head title={advisory} />
            <Page size="narrow">
                <PageHeader
                    title={detail.finding.title ?? advisory}
                    description={
                        <MetaLine>
                            <StatusWord detail={detail} />
                            <span>
                                First seen{' '}
                                <TimeAgo value={detail.finding.firstSeenAt} />
                            </span>
                            {detail.lastSeenAt &&
                                detail.finding.status !== 'resolved' && (
                                    <span>
                                        Last checked{' '}
                                        <TimeAgo value={detail.lastSeenAt} />
                                    </span>
                                )}
                        </MetaLine>
                    }
                >
                    <div className="mt-3 flex flex-wrap items-center gap-2">
                        <FindingActions detail={detail} onChanged={refresh} />
                        {siblings.position !== null && siblings.total > 1 && (
                            <span className="ml-auto flex items-center">
                                <SiblingLink
                                    href={siblings.previous}
                                    label="Previous vulnerability"
                                >
                                    <ChevronLeft aria-hidden="true" />
                                </SiblingLink>
                                <span className="px-1 type-small text-muted-foreground tabular-nums">
                                    {siblings.position} of {siblings.total}
                                </span>
                                <SiblingLink
                                    href={siblings.next}
                                    label="Next vulnerability"
                                >
                                    <ChevronRight aria-hidden="true" />
                                </SiblingLink>
                            </span>
                        )}
                    </div>
                </PageHeader>
                <div className="flex flex-col divide-y overflow-hidden rounded-xl border bg-card shadow-card">
                    <FindingDetailBody
                        detail={detail}
                        onChanged={refresh}
                        actionsInHeader
                    />
                </div>
            </Page>
        </>
    );
}

function SiblingLink({
    href,
    label,
    children,
}: {
    href: string | null;
    label: string;
    children: ReactNode;
}) {
    if (!href) {
        return (
            <Button size="icon-sm" variant="ghost" disabled aria-label={label}>
                {children}
            </Button>
        );
    }

    return (
        <Button size="icon-sm" variant="ghost" asChild>
            <Link href={href} aria-label={label}>
                {children}
            </Link>
        </Button>
    );
}

FindingPage.layout = {
    breadcrumbs: [{ title: 'Security', href: index() }],
};
