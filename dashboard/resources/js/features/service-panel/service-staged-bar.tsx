import { useEffect, useState, useSyncExternalStore } from 'react';
import { StagedBar } from '@/components/patterns/unsaved-bar';
import { useAnyDirty } from '@/features/service-settings/dirty';
import {
    deployStaged,
    discardStaged,
    openStagedChanges,
} from '@/features/staged-changes/staged-changes-sheet';
import { EFFECTS } from '@/features/staged-changes/effects';
import { useStagedChanges } from '@/features/staged-changes/use-staged-changes';

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
 * The panel's floating bar for this service's staged changes (flows §8):
 * "2 changes", "web will restart, 3 more elsewhere", Details, Discard
 * web’s changes, Apply all 5. Discard drops this service's changes; Apply
 * applies everything staged in the environment (the affected services
 * redeploy) and keeps you on the canvas. Both say their scope.
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
    const others = data.count - group.items.length;

    return (
        <StagedBar
            placement="inline"
            count={Math.max(group.items.length, 1)}
            summary={[
                `${name} ${EFFECTS[group.effect].consequence ?? EFFECTS[group.effect].label}`,
                ...(others > 0 ? [`${others} more elsewhere`] : []),
            ].join(', ')}
            onDetails={openStagedChanges}
            onDiscard={() =>
                void discardStaged(
                    project,
                    environment,
                    group.items.map((item) => item.path),
                )
            }
            discardLabel={others > 0 ? `Discard ${name}’s changes` : 'Discard'}
            onDeploy={() => {
                setDeploying(true);
                deployStaged(project, environment, {
                    onFinish: () => setDeploying(false),
                });
            }}
            deploying={deploying}
            deployLabel={others > 0 ? `Apply all ${data.count}` : 'Apply'}
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
