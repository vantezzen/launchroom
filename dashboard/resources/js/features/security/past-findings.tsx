import { ChevronRight } from 'lucide-react';
import { useState } from 'react';
import { SheetSection, SidePanel } from '@/components/patterns/sheet';
import { cn } from '@/lib/utils';
import { SeverityText } from './finding-card';
import { card } from './format';

type Finding = App.Data.Security.FindingCardData;
type Kind = 'dismissed' | 'fixed';

const TITLE: Record<Kind, (count: number) => string> = {
    dismissed: (count) => `${count} marked not affected`,
    fixed: (count) => `${count} fixed in the last 30 days`,
};

/**
 * Dismissed and recently fixed findings stay findable (docs/22 §9.2): two
 * quiet rows under "Action needed" in the Worth-knowing style, each
 * opening its list in a sheet; a finding opens its own sheet.
 */
export function PastFindings({
    dismissed,
    fixed,
    onOpen,
}: {
    dismissed: Finding[];
    fixed: Finding[];
    onOpen: (finding: Finding) => void;
}) {
    const [open, setOpen] = useState<Kind | null>(null);
    const rows = (
        [
            ['dismissed', dismissed],
            ['fixed', fixed],
        ] as const
    ).filter(([, list]) => list.length > 0);

    if (rows.length === 0) {
        return null;
    }

    const list =
        open === 'dismissed' ? dismissed : open === 'fixed' ? fixed : [];

    return (
        <>
            <ul
                aria-label="Past findings"
                className={cn(
                    card,
                    'grid grid-cols-1 overflow-hidden',
                    rows.length > 1 && 'md:grid-cols-2',
                )}
            >
                {rows.map(([kind, items], index) => (
                    <li
                        key={kind}
                        className={cn(
                            index > 0 && 'border-t md:border-t-0 md:border-l',
                        )}
                    >
                        <button
                            type="button"
                            onClick={() => setOpen(kind)}
                            className="flex w-full items-center gap-4 px-5 py-3.5 text-left hover:bg-hover focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-signal"
                        >
                            <span className="w-10 shrink-0 text-right type-heading tabular-nums">
                                {items.length.toLocaleString('en')}
                            </span>
                            <span className="min-w-0 flex-1 truncate type-body text-muted-foreground">
                                {kind === 'dismissed'
                                    ? 'Marked not affected'
                                    : 'Fixed in the last 30 days'}
                            </span>
                            <ChevronRight
                                aria-hidden="true"
                                className="size-4 shrink-0 text-faint"
                            />
                        </button>
                    </li>
                ))}
            </ul>

            <SidePanel
                open={open !== null}
                onOpenChange={(value) => !value && setOpen(null)}
                size="md"
                title={open ? TITLE[open](list.length) : ''}
            >
                <SheetSection>
                    <ul className="-mx-5 flex flex-col divide-y">
                        {list.map((finding) => (
                            <li key={finding.id}>
                                <button
                                    type="button"
                                    onClick={() => {
                                        setOpen(null);
                                        onOpen(finding);
                                    }}
                                    className="flex w-full min-w-0 flex-col gap-1 px-5 py-3 text-left hover:bg-hover focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-signal"
                                >
                                    <span className="flex min-w-0 items-baseline gap-3">
                                        <code className="min-w-0 truncate type-mono">
                                            {finding.package}{' '}
                                            <span className="text-muted-foreground">
                                                {finding.version}
                                            </span>
                                        </code>
                                        <SeverityText
                                            severity={finding.severity}
                                            className="ml-auto type-small"
                                        />
                                    </span>
                                    <span className="truncate type-small text-muted-foreground">
                                        {finding.title ?? finding.advisory}
                                    </span>
                                    <span className="truncate type-small text-faint">
                                        {finding.service.name}
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                </SheetSection>
            </SidePanel>
        </>
    );
}
