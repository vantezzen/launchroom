import { Form } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Section } from '@/components/patterns/section';
import { Status } from '@/components/status';
import type { StatusState } from '@/components/status';
import { StepList } from '@/components/steps/step-list';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import type { UpdatesPageProps } from '@/features/updates/update-status';
import { fleetSteps } from '@/features/updates/update-steps';
import { servers as updateServers } from '@/routes/updates';

function serverState(server: App.Data.Updates.FleetServerData): {
    state: StatusState;
    label: string;
} {
    if (!server.compatible) {
        return { state: 'failed', label: 'Can’t talk to this dashboard' };
    }

    if (server.outdated) {
        return { state: 'warning', label: 'Update available' };
    }

    if (server.newer) {
        return { state: 'idle', label: 'Newer than the dashboard' };
    }

    return { state: 'active', label: 'Up to date' };
}

function ConfirmServers({
    count,
    version,
    disabled,
    label,
}: {
    count: number;
    version: string;
    disabled: boolean;
    label?: string;
}) {
    const [open, setOpen] = useState(false);
    const servers = count === 1 ? '1 server' : `${count} servers`;

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant="outline" disabled={disabled}>
                    {label ?? `Update ${servers}`}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>
                    Update {servers} to {version}?
                </DialogTitle>
                <DialogDescription>
                    One server at a time, edge servers last. Each one goes back
                    by itself if the new version doesn’t start, and running apps
                    aren’t restarted. The rollout stops at the first problem.
                </DialogDescription>
                <Form
                    {...updateServers.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                >
                    {({ processing, errors }) => (
                        <>
                            <InputError message={errors.fleet} />
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="ghost" type="button">
                                        Cancel
                                    </Button>
                                </DialogClose>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    Update servers
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Servers on Settings → Updates: one small card per server with its
 * version, the rolling update when it runs on its own (not as part of a
 * control-plane update, which shows it in the hero) and what to do after a
 * server didn't take the update.
 */
export function ServerVersions({
    currentVersion,
    servers: list,
    fleetUpdate,
    controlPlaneUpdate,
    serversToUpdate,
    fleetUpdatable,
    running,
}: UpdatesPageProps) {
    const partOfControlPlane =
        fleetUpdate !== null &&
        controlPlaneUpdate !== null &&
        controlPlaneUpdate.fleetUpdateId === fleetUpdate.id &&
        fleetUpdate.status === 'running';
    const failed = fleetUpdate !== null && fleetUpdate.status === 'failed';
    const failedStep = failed
        ? (fleetUpdate.steps.find(
              (step) =>
                  step.status === 'failed' || step.status === 'rolled_back',
          ) ?? null)
        : null;

    return (
        <Section
            title="Servers"
            description="Updated one at a time, edge servers last."
            actions={
                fleetUpdatable &&
                serversToUpdate > 0 &&
                !failed && (
                    <ConfirmServers
                        count={serversToUpdate}
                        version={currentVersion}
                        disabled={running}
                    />
                )
            }
        >
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {list.map((server) => {
                    const { state, label } = serverState(server);

                    return (
                        <div
                            key={server.id}
                            className="flex flex-col gap-3 rounded-xl border bg-card p-4 shadow-card"
                        >
                            <div className="flex min-w-0 flex-col gap-0.5">
                                <span className="truncate type-label">
                                    {server.name}
                                </span>
                                {server.isControlPlane && (
                                    <span className="type-small text-muted-foreground">
                                        Updated with launchroom
                                    </span>
                                )}
                            </div>
                            <div className="flex flex-col gap-1">
                                <span className="type-mono">
                                    {server.daemonVersion ?? 'unknown'}
                                </span>
                                <Status state={state} label={label} />
                            </div>
                        </div>
                    );
                })}
            </div>

            {fleetUpdate !== null &&
                fleetUpdate.status === 'running' &&
                !partOfControlPlane && (
                    <div className="flex flex-col gap-3">
                        <Status
                            state="progress"
                            label={`Updating servers to ${fleetUpdate.toVersion}`}
                        />
                        <StepList steps={fleetSteps(fleetUpdate)} />
                    </div>
                )}

            {failed && (
                <div className="flex flex-col gap-4 rounded-xl border bg-card p-5 shadow-card">
                    <div className="flex flex-col gap-1">
                        <Status
                            state="warning"
                            label={`The server update to ${fleetUpdate.toVersion} stopped`}
                        />
                        <p className="pl-5 type-body text-muted-foreground">
                            {failedStep !== null
                                ? `${failedStep.serverName} ${failedStep.status === 'rolled_back' ? `went back to ${failedStep.fromVersion ?? 'its version'} by itself` : 'didn’t take the update'}; the servers after it weren’t touched. Apps on every server kept running.`
                                : (fleetUpdate.error ??
                                  'A server didn’t take the update.')}
                        </p>
                        <p className="pl-5 type-small text-muted-foreground">
                            Check that the server is online and has free disk
                            space, then update the remaining servers again.
                        </p>
                    </div>
                    <StepList steps={fleetSteps(fleetUpdate)} />
                    {fleetUpdatable && serversToUpdate > 0 && (
                        <div>
                            <ConfirmServers
                                count={serversToUpdate}
                                version={currentVersion}
                                disabled={running}
                                label="Try again"
                            />
                        </div>
                    )}
                </div>
            )}

            {fleetUpdate !== null && fleetUpdate.status === 'succeeded' && (
                <p className="type-small text-muted-foreground">
                    All servers updated to {fleetUpdate.toVersion}{' '}
                    <TimeAgo value={fleetUpdate.finishedAt} />.
                </p>
            )}
        </Section>
    );
}
