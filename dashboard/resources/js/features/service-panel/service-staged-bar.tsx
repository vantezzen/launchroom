import { useEffect, useState, useSyncExternalStore } from 'react';
import { StagedBar } from '@/components/patterns/unsaved-bar';
import { useAnyDirty } from '@/features/service-settings/dirty';
import {
    deployStaged,
    discardStaged,
    openStagedChanges,
} from '@/features/staged-changes/staged-changes-sheet';
import { EFFECTS } from '@/features/staged-changes/effects';
import {
    stagedCountLabel,
    useStagedChanges,
} from '@/features/staged-changes/use-staged-changes';

/* Whether the bar is showing: the header's "Review & apply" steps aside
   while it does, so staged changes have one home in the panel. */
let shown = 0;
const listeners = new Set<() => void>();

function emit(): void {
    listeners.forEach((listener) => listener());
}

export function useStagedBarShown(): boolean {
    return useSyncExternalStore(
        (listener) => {
            listeners.add(listener);

            return () => listeners.delete(listener);
        },
        () => shown > 0,
        () => false,
    );
}

function useReportShown(visible: boolean): void {
    useEffect(() => {
        if (!visible) {
            return;
        }

        shown++;
        emit();

        return () => {
            shown--;
            emit();
        };
    }, [visible]);
}

/**
 * The panel's floating bar for staged changes (flows §8, D109): "4 changes
 * in 2 services", "web will restart", Review, Apply. Apply applies
 * everything staged in the environment (the affected services redeploy)
 * and keeps you on the canvas; the count says that scope. Discard sits in
 * the bar only while every change is this service's; otherwise it lives
 * in the review, where you see what it drops.
 */
export function ServiceStagedBar({
    project,
    environment,
    service,
}: {
    project: string;
    environment: string;
    service: string;
}) {
    const { data } = useStagedChanges(project, environment);
    const dirty = useAnyDirty();
    const [deploying, setDeploying] = useState(false);
    const group = data?.groups.find((item) => item.serviceId === service);
    const visible = Boolean(data && group && !dirty);

    useReportShown(visible);

    if (!data || !group || dirty) {
        return null;
    }

    const name = group.slug ?? group.name;
    const onlyThisService = data.count === group.items.length;
    const consequence =
        EFFECTS[group.effect].consequence ?? EFFECTS[group.effect].label;

    return (
        <StagedBar
            placement="inline"
            count={Math.max(data.count, 1)}
            countLabel={stagedCountLabel(data)}
            summary={`${name} ${consequence}`}
            onReview={openStagedChanges}
            onDiscard={
                onlyThisService
                    ? () =>
                          void discardStaged(
                              project,
                              environment,
                              group.items.map((item) => item.path),
                          )
                    : undefined
            }
            onDeploy={() => {
                setDeploying(true);
                deployStaged(project, environment, {
                    onFinish: () => setDeploying(false),
                });
            }}
            deploying={deploying}
            disabledReason={
                data.canDeploy
                    ? data.blockers.length > 0
                        ? 'Fix the broken references first'
                        : undefined
                    : 'Applying changes needs the developer role'
            }
        />
    );
}
