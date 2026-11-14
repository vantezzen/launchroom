import { StagedBar } from '@/components/patterns/unsaved-bar';
import { useAnyDirty } from '@/features/service-settings/dirty';
import { useState } from 'react';
import { deployStaged, discardStaged } from './staged-changes-sheet';
import { effectLabel, useStagedChanges } from './use-staged-changes';

const consequence: Partial<Record<App.Enums.ChangeEffect, string>> = {
    restart: 'will restart',
    rebuild: 'will rebuild',
    new: 'starts when applied',
    remove: 'is removed when applied',
    route: 'changes routing',
};

/**
 * The panel's floating bar for this service's staged changes (flows §8):
 * "2 changes", "api will restart", Discard, Apply. Discard drops this
 * service's changes; Apply applies everything staged in the environment
 * (the affected services redeploy) and keeps you on the canvas.
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
                `${name} ${consequence[group.effect] ?? effectLabel(group.effect)}`,
                ...(others > 0 ? [`${others} more elsewhere`] : []),
            ].join(', ')}
            onDiscard={() =>
                void discardStaged(
                    project,
                    environment,
                    group.items.map((item) => item.path),
                )
            }
            onDeploy={() => {
                setDeploying(true);
                deployStaged(project, environment, {
                    onFinish: () => setDeploying(false),
                });
            }}
            deploying={deploying}
            deployLabel={others > 0 ? 'Apply all' : 'Apply'}
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
