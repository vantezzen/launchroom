import { Box, Globe, Server } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Page, PageHeader } from '@/components/patterns/page';
import { TracePasteForm } from './trace-paste';

const GHOST: { key: string; icon: LucideIcon; label: string }[] = [
    { key: 'visitor', icon: Globe, label: 'Visitor' },
    { key: 'edge', icon: Server, label: 'Edge server' },
    { key: 'app', icon: Server, label: 'App server' },
    { key: 'service', icon: Box, label: 'Service' },
];

/** The path a trace will draw, as a quiet outline above the paste field. */
function GhostPath() {
    return (
        <div aria-hidden="true" className="relative grid grid-cols-4 px-2 py-6">
            <span className="absolute inset-x-[12.5%] top-[calc(1.5rem+20px)] border-t-[1.5px] border-dashed border-border-strong" />
            {GHOST.map(({ key, icon: Icon, label }) => (
                <span
                    key={key}
                    className="relative flex flex-col items-center gap-2 text-center"
                >
                    <span className="flex size-10 items-center justify-center rounded-xl border border-dashed border-border-strong bg-recessed text-faint">
                        <Icon className="size-4" />
                    </span>
                    <span className="type-small text-muted-foreground">
                        {label}
                    </span>
                </span>
            ))}
        </div>
    );
}

/**
 * The trace page without a trace (docs/23 §6): the paste field, with the
 * server's answer when the last paste couldn't be read.
 */
export function TraceEmpty({
    error,
    input,
}: {
    error?: string | null;
    input?: string | null;
}) {
    return (
        <Page size="narrow">
            <PageHeader
                title="Paste a ray"
                description="Every response through your edge servers carries a ray. Paste it to see where the request went."
            />
            <div className="flex flex-col gap-3">
                <div className="flex flex-col overflow-hidden rounded-xl border bg-card shadow-card">
                    <div className="border-b bg-recessed">
                        <GhostPath />
                    </div>
                    <div className="p-5">
                        <TracePasteForm
                            key={`${input ?? ''}|${error ?? ''}`}
                            initial={input ?? ''}
                            error={error}
                            autoFocus
                        />
                    </div>
                </div>
                <p className="type-small text-muted-foreground">
                    A response without an X-Launchroom-Ray header didn’t pass
                    through a launchroom edge server.
                </p>
            </div>
        </Page>
    );
}
