import { Form, Link } from '@inertiajs/react';
import { useState } from 'react';
import { StepCard } from '@/components/steps/step-card';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import {
    CutoverDnsStep,
    CutoverImagesStep,
    CutoverServersStep,
} from '@/features/moves/cutover-steps';
import { fromDnsSwitch } from '@/features/moves/shape';
import { TransferStrip } from '@/features/moves/transfer-strip';
import { index as backupSettings } from '@/routes/backups';
import { finish, rebuild } from '@/routes/move';

type Machine = App.Data.Moves.CutoverMachineData;

export type Checklist = App.Data.Moves.MoveChecklistData;

/**
 * The new dashboard after a move (docs/14 §13.4): switch DNS (each record
 * with its value and a live check), rebuild missing images, other servers,
 * the old machine; then Finish.
 */
export function MoveChecklist({
    checklist,
    onCheck,
}: {
    checklist: Checklist;
    /** Runs the checks again (DNS lookups, the registry). */
    onCheck: () => void;
}) {
    const { checks, openSteps: open } = checklist;
    const [openId, setOpenId] = useState<string | null>(
        open[0] === 'recovery-kit' ? 'old' : (open[0] ?? 'old'),
    );
    const addresses = checks.expectedAddresses;

    return (
        <>
            <p className="max-w-[65ch] type-body text-muted-foreground">
                launchroom moved to {checklist.current?.name ?? 'this server'}
                {checklist.startedAt !== null && (
                    <>
                        {' '}
                        <TimeAgo value={checklist.startedAt} />
                    </>
                )}
                . Finish these steps, then close the checklist.
            </p>

            {checklist.previous !== null && checklist.current !== null && (
                <MachinesStrip
                    previous={checklist.previous}
                    current={checklist.current}
                    dnsSwitch={checklist.dnsSwitch}
                />
            )}

            <ol className="flex flex-col gap-3">
                <CutoverDnsStep
                    checks={checks}
                    number={1}
                    open={openId === 'dns'}
                    onOpen={() => setOpenId('dns')}
                    onCheck={onCheck}
                    records={
                        checklist.dnsSwitch
                            ? fromDnsSwitch(checklist.dnsSwitch)
                            : undefined
                    }
                >
                    <p className="max-w-[65ch] type-body">
                        {addresses.length > 0
                            ? `At your DNS provider, set each record below to ${addresses.join(' and ')}${checklist.previous !== null && checklist.previous.addresses.length > 0 ? `, in place of ${checklist.previous.addresses.join(', ')}` : ''}. Certificates follow by themselves once a record points here.`
                            : 'No edge server has a public address yet. The records to set appear here once it reports one.'}
                    </p>
                </CutoverDnsStep>

                <CutoverImagesStep
                    checks={checks}
                    number={2}
                    open={openId === 'images'}
                    onOpen={() => setOpenId('images')}
                    onCheck={onCheck}
                    title="Rebuild app images"
                    rebuild={rebuild.form()}
                >
                    Images weren’t copied. Running apps keep their current
                    image, and each app builds again from its commit on its next
                    deploy. Rebuild now to have them ready.
                </CutoverImagesStep>

                <CutoverServersStep
                    checks={checks}
                    number={3}
                    open={openId === 'servers'}
                    onOpen={() => setOpenId('servers')}
                    title="Other servers"
                    notSeen="Not seen since the move. If the dashboard’s address changed, set LR_CONTROL_PLANE_URL in launchroomd/.env on that server and run docker compose up -d there."
                />

                <StepCard
                    id="old"
                    number={4}
                    status="pending"
                    title="Delete the old machine"
                    line={checklist.previous?.name ?? 'When DNS points here'}
                    open={openId === 'old'}
                    onOpen={() => setOpenId('old')}
                >
                    <p className="max-w-[65ch] type-body">
                        {checklist.previous?.name ?? 'The old server'} is
                        retired. It no longer runs anything for launchroom, and
                        its key is revoked. Once DNS points here, delete the
                        machine, or run{' '}
                        <code className="type-mono-small">
                            ./launchroom uninstall
                        </code>{' '}
                        in its install folder.
                    </p>
                    {checklist.previousUrl !== null &&
                        checklist.previousUrl !== checklist.url && (
                            <p className="type-small text-muted-foreground">
                                The dashboard’s address changed from{' '}
                                {checklist.previousUrl} to {checklist.url}.
                            </p>
                        )}
                    {checklist.needsRecoveryKit && (
                        <p className="type-small">
                            Download a new recovery kit in{' '}
                            <Link
                                href={backupSettings()}
                                className="underline underline-offset-4"
                            >
                                Settings → Backups
                            </Link>
                            .
                        </p>
                    )}
                </StepCard>
            </ol>

            <Form {...finish.form()}>
                {({ processing }) => (
                    <div className="flex flex-wrap items-center gap-3">
                        <Button
                            type="submit"
                            variant={open.length === 0 ? 'default' : 'outline'}
                            disabled={processing}
                        >
                            {processing && <Spinner />}
                            Finish
                        </Button>
                        {open.length > 0 && (
                            <span className="type-small text-muted-foreground">
                                Some steps are still open. Finishing now records
                                that in the audit log.
                            </span>
                        )}
                    </div>
                )}
            </Form>
        </>
    );
}

/**
 * The same two machines as during the move: the old one retired, this one
 * running the dashboard, and how far the DNS switch got on the arrow.
 */
function MachinesStrip({
    previous,
    current,
    dnsSwitch,
}: {
    previous: Machine;
    current: Machine;
    dnsSwitch: App.Data.Moves.DnsSwitchData | null;
}) {
    return (
        <TransferStrip
            source={{
                name: previous.name,
                device: previous.device?.kind ?? 'unknown',
                status: 'offline',
                gone: true,
                tags: ['Retired'],
            }}
            target={{
                name: current.name,
                device: current.device?.kind ?? 'unknown',
                status: 'online',
                tags: ['Runs the dashboard'],
            }}
            step={
                dnsSwitch !== null && dnsSwitch.checked && dnsSwitch.total > 0
                    ? `Handed over, ${dnsSwitch.pointed} of ${dnsSwitch.total} records switched`
                    : 'Handed over'
            }
        />
    );
}
