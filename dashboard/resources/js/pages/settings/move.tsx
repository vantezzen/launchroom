import { Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { ControlPlaneMoveSteps } from '@/features/moves/control-plane-move';
import type { ControlPlaneMoveProps } from '@/features/moves/control-plane-move';
import { MoveChecklist } from '@/features/moves/move-checklist';
import SettingsLayout from '@/features/settings/settings-layout';
import { schedulePoll, useLiveProps } from '@/hooks/use-live-props';
import { edit, stop } from '@/routes/move';

type Props = Omit<
    ControlPlaneMoveProps,
    'stopForm' | 'onCheckDns' | 'checkingDns'
> & {
    checklist: App.Data.Moves.MoveChecklistData | null;
};

/** DNS checks repeat this often while records still point elsewhere. */
const DNS_CHECK_MS = 15_000;

/**
 * Settings → Move launchroom (docs/14 §13.4): prepare, connect the new
 * server, move the apps and data, hand over the dashboard; on the old
 * dashboard afterwards, the DNS switch. On the new dashboard after a move,
 * the checklist (DNS, images, servers, the old machine).
 */
export default function MoveLaunchroom({ checklist, ...props }: Props) {
    const status = props.move?.status ?? null;
    const [checkingDns, setCheckingDns] = useState(false);
    const dnsSwitch = props.dnsSwitch;
    const dnsPending =
        status === 'handed_over' &&
        dnsSwitch !== null &&
        dnsSwitch !== undefined &&
        dnsSwitch.records.length > 0 &&
        (!dnsSwitch.checked || dnsSwitch.pointed < dnsSwitch.total);

    useLiveProps(
        ['move', 'serverMove', 'plan', 'preflight', 'handoverBlockers'],
        {
            while:
                checklist === null &&
                status !== null &&
                status !== 'cancelled' &&
                status !== 'handed_over',
            everyMs: 4000,
        },
    );

    const checkDns = () =>
        router.reload({
            only: ['dnsSwitch'],
            data: { check: 1 },
            onStart: () => setCheckingDns(true),
            onFinish: () => setCheckingDns(false),
        });

    // The old dashboard's final screen keeps checking until every record switched.
    useEffect(() => {
        if (!dnsPending) {
            return;
        }

        return schedulePoll(
            () => router.reload({ only: ['dnsSwitch'], data: { check: 1 } }),
            DNS_CHECK_MS,
        );
    }, [dnsPending]);

    return (
        <>
            <Head title="Move launchroom" />
            {checklist !== null ? (
                <MoveChecklist
                    checklist={checklist}
                    onCheck={() =>
                        router.visit(edit({ query: { check: 1 } }), {
                            preserveScroll: true,
                            preserveState: true,
                        })
                    }
                />
            ) : (
                <ControlPlaneMoveSteps
                    {...props}
                    stopForm={(item) => stop.form(item)}
                    onCheckDns={() => checkDns()}
                    checkingDns={checkingDns}
                />
            )}
        </>
    );
}

MoveLaunchroom.layout = SettingsLayout;
