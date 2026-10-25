import { useQueryClient } from '@tanstack/react-query';
import { ArrowRight } from 'lucide-react';
import { useEffect } from 'react';
import { ChannelEvents } from '@/features/deployments/realtime';
import { cn } from '@/lib/utils';
import { openStagedChanges, StagedChangesSheet } from './staged-changes-sheet';
import { stagedChangesKey, useStagedChanges } from './use-staged-changes';

export type StagedChangesPillProps = {
    project: string;
    environment: string;
    /**
     * False on the canvas: its floating toolbar carries the button
     * (StagedToolbarButton). The sheet and realtime refresh stay mounted.
     */
    showButton?: boolean;
};

/**
 * What the staged button says (design-system §13.9): "4 changes · Review &
 * deploy", "3 new services · Deploy" before the first deploy, or nothing to
 * show when nothing is staged (also for an environment without services).
 */
export function useStagedSummary(project: string, environment: string) {
    const { data } = useStagedChanges(project, environment);
    const services = data?.groups.length ?? 0;
    const visible =
        data !== undefined &&
        (data.count > 0 || (data.neverDeployed && services > 0));
    const number = data?.neverDeployed ? services : (data?.count ?? 0);
    const count = data?.neverDeployed
        ? `${services} new ${services === 1 ? 'service' : 'services'}`
        : `${data?.count ?? 0} ${data?.count === 1 ? 'change' : 'changes'}`;
    const action = data?.canDeploy
        ? data.neverDeployed
            ? 'Deploy'
            : 'Review & deploy'
        : 'Review';

    return { visible, number, count, action };
}

/**
 * The project top bar's staged button (direction §3.2, flows §5): dashed =
 * staged. Opens the review sheet over the current page; hidden when nothing
 * is staged. Renders the sheet too, so every project page has it.
 */
export function StagedChangesPill({
    project,
    environment,
    showButton = true,
}: StagedChangesPillProps) {
    const queryClient = useQueryClient();
    const { visible, number, count, action } = useStagedSummary(
        project,
        environment,
    );

    const refresh = () =>
        void queryClient.invalidateQueries({
            queryKey: stagedChangesKey(environment),
        });

    return (
        <>
            <ChannelEvents
                channel={`environments.${environment}`}
                event=".canvas.changed"
                pollMs={30_000}
                onEvent={refresh}
            />
            <StagedChangesSheet project={project} environment={environment} />
            {showButton && visible && (
                <button
                    type="button"
                    onClick={openStagedChanges}
                    aria-live="polite"
                    className={cn(
                        'inline-flex h-8 items-center gap-2 rounded-full border border-dashed border-signal bg-signal-soft pr-3 pl-3 type-label text-signal transition-colors duration-fast sm:pr-1',
                        'hover:bg-signal-soft/70 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none',
                    )}
                >
                    <span
                        aria-hidden="true"
                        className="size-1.5 rounded-full border border-dashed border-signal"
                    />
                    {/* Phones keep the number only, so the project and
                        environment names fit in the bar. */}
                    <span className="tabular-nums sm:hidden">
                        {number}
                        <span className="sr-only"> {count} staged</span>
                    </span>
                    <span className="hidden tabular-nums sm:inline">
                        {count}
                        <span className="sr-only"> staged</span>
                    </span>
                    <span className="hidden h-6 items-center rounded-full bg-signal px-2.5 text-background sm:inline-flex">
                        {action}
                    </span>
                </button>
            )}
        </>
    );
}

/**
 * The canvas' "Review & deploy" (design-system §13.9): the end of the
 * floating toolbar, bottom centre, next to where changes are made.
 * "4 changes · Review & deploy" with the count in signal and the action as
 * the toolbar's one filled button. Renders nothing when nothing is staged,
 * so the toolbar stays as it is. Same sheet as the top-bar pill.
 */
export function StagedToolbarButton({
    project,
    environment,
    divider = false,
    className,
}: {
    project: string;
    environment: string;
    /** A hairline before it (inside the canvas toolbar). */
    divider?: boolean;
    className?: string;
}) {
    const { visible, count, action } = useStagedSummary(project, environment);

    // Shift+D opens the review from anywhere on the canvas.
    useEffect(() => {
        if (!visible) {
            return;
        }

        const onKey = (event: KeyboardEvent) => {
            const target = event.target as HTMLElement | null;

            if (
                event.key === 'D' &&
                event.shiftKey &&
                !event.metaKey &&
                !event.ctrlKey &&
                !event.altKey &&
                !target?.closest(
                    'input, textarea, select, [contenteditable="true"], [role="dialog"]',
                )
            ) {
                event.preventDefault();
                openStagedChanges();
            }
        };

        document.addEventListener('keydown', onKey);

        return () => document.removeEventListener('keydown', onKey);
    }, [visible]);

    if (!visible) {
        return null;
    }

    return (
        <>
            {divider && (
                <span
                    aria-hidden="true"
                    className="mx-0.5 h-4 w-px bg-border"
                />
            )}
            <button
                type="button"
                onClick={openStagedChanges}
                aria-keyshortcuts="Shift+D"
                title={`${action}: ${count} staged (Shift+D)`}
                className={cn(
                    'inline-flex h-8 animate-enter items-center gap-2 rounded-lg pr-1 pl-2.5 type-label text-signal transition-colors duration-fast hover:bg-signal-soft focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                    className,
                )}
            >
                <span
                    aria-hidden="true"
                    className="size-1.5 rounded-full border border-dashed border-signal"
                />
                <span aria-live="polite" className="tabular-nums">
                    {count}
                    <span className="sr-only"> staged</span>
                </span>
                <span className="inline-flex h-6 items-center gap-1 rounded-md bg-signal px-2.5 text-background">
                    {action}
                    <ArrowRight aria-hidden="true" className="size-3.5" />
                </span>
            </button>
        </>
    );
}
