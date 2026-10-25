import { ChevronRight } from 'lucide-react';
import { useState } from 'react';
import { Section } from '@/components/patterns/section';
import { SheetSection, SidePanel } from '@/components/patterns/sheet';
import { cn } from '@/lib/utils';
import { SeverityText } from './finding-card';
import { card, plural } from './format';

type Group = App.Data.Security.WorthKnowingGroupData;
type Item = App.Data.Security.WorthKnowingItemData;

/**
 * "Worth knowing" (docs/22 §9.2 part 4): one quiet row per reason with its
 * count; a row opens the list in a sheet. Never notifies.
 */
export function WorthKnowing({
    groups,
    notices,
    onOpen,
}: {
    groups: Group[];
    notices: string[];
    onOpen: (href: string) => void;
}) {
    const [open, setOpen] = useState<string | null>(null);
    const group = groups.find((g) => g.reason === open) ?? null;

    if (groups.length === 0 && notices.length === 0) {
        return null;
    }

    return (
        <Section title="Worth knowing">
            <div className={cn(card, 'overflow-hidden')}>
                {notices.length > 0 && (
                    <ul className="flex flex-col gap-1 border-b bg-recessed px-5 py-3">
                        {notices.map((notice) => (
                            <li
                                key={notice}
                                className="type-small text-muted-foreground"
                            >
                                {notice}
                            </li>
                        ))}
                    </ul>
                )}
                <ul className="grid grid-cols-1 md:grid-cols-2">
                    {groups.map((g, index) => (
                        <li
                            key={g.reason}
                            data-worth-group={g.reason}
                            className={cn(
                                'border-border',
                                index > 0 && 'border-t',
                                index === 1 && 'md:border-t-0',
                                index % 2 === 1 && 'md:border-l',
                            )}
                        >
                            <button
                                type="button"
                                onClick={() => setOpen(g.reason)}
                                className="flex w-full items-center gap-4 px-5 py-3.5 text-left hover:bg-hover focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-signal"
                            >
                                <span className="w-10 shrink-0 text-right type-heading tabular-nums">
                                    {g.count.toLocaleString('en')}
                                </span>
                                <span className="min-w-0 flex-1 truncate type-body text-muted-foreground">
                                    {g.label}
                                </span>
                                <ChevronRight
                                    aria-hidden="true"
                                    className="size-4 shrink-0 text-faint"
                                />
                            </button>
                        </li>
                    ))}
                </ul>
            </div>

            <SidePanel
                open={group !== null}
                onOpenChange={(value) => !value && setOpen(null)}
                size="md"
                title={
                    group
                        ? `${group.count.toLocaleString('en')} ${group.label}`
                        : ''
                }
            >
                {group && (
                    <GroupList
                        group={group}
                        onOpen={(href) => {
                            setOpen(null);
                            onOpen(href);
                        }}
                    />
                )}
            </SidePanel>
        </Section>
    );
}

function GroupList({
    group,
    onOpen,
}: {
    group: Group;
    onOpen: (href: string) => void;
}) {
    const fact =
        group.reason === 'end_of_life' || group.reason === 'patch_available';

    return (
        <SheetSection>
            <ul className="-mx-5 flex flex-col divide-y">
                {group.items.map((item) =>
                    fact ? (
                        <li key={item.key} className="px-5 py-3 type-body">
                            {item.text}
                        </li>
                    ) : (
                        <ItemRow key={item.key} item={item} onOpen={onOpen} />
                    ),
                )}
            </ul>
            {group.items.length < group.count && (
                <p className="pt-3 type-small text-muted-foreground">
                    The first {group.items.length.toLocaleString('en')} of{' '}
                    {group.count.toLocaleString('en')} are shown. Search the
                    inventory for the rest.
                </p>
            )}
        </SheetSection>
    );
}

function ItemRow({
    item,
    onOpen,
}: {
    item: Item;
    onOpen: (href: string) => void;
}) {
    const services = item.services.map((s) => s.name);

    return (
        <li>
            <button
                type="button"
                disabled={!item.href}
                onClick={() => item.href && onOpen(item.href)}
                className="flex w-full min-w-0 flex-col gap-1 px-5 py-3 text-left focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-signal enabled:hover:bg-hover"
            >
                <span className="flex min-w-0 items-baseline gap-3">
                    <code className="min-w-0 truncate type-mono">
                        {item.package}{' '}
                        <span className="text-muted-foreground">
                            {item.version}
                        </span>
                    </code>
                    <SeverityText
                        severity={item.severity}
                        className="ml-auto type-small"
                    />
                </span>
                <span className="truncate type-small text-muted-foreground">
                    {item.title ?? item.advisory}
                </span>
                {services.length > 0 && (
                    <span className="truncate type-small text-faint">
                        {services.length <= 3
                            ? services.join(', ')
                            : `${services.slice(0, 3).join(', ')} and ${plural(services.length - 3, 'more', 'more')}`}
                    </span>
                )}
            </button>
        </li>
    );
}
