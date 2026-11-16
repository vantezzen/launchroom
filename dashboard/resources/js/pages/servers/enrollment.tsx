import { Head, Link } from '@inertiajs/react';
import { LayoutDashboard } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { ConfirmAction } from '@/components/confirm-action';
import { DeviceImage, DEVICE_LABEL } from '@/components/devices/device-image';
import { LiveWait } from '@/components/patterns/live-wait';
import { MetaLine } from '@/components/patterns/meta-line';
import { Page, PageHeader } from '@/components/patterns/page';
import { Wizard } from '@/components/patterns/wizard';
import { StatusIcon, StatusPill, serverStatus } from '@/components/status';
import { Spinner } from '@/components/ui/spinner';
import { Button } from '@/components/ui/button';
import {
    ADD_SERVER_STEPS,
    InstallCommand,
    machine as findMachine,
} from '@/features/servers/add-server';
import { deviceKind, deviceStatus, specParts } from '@/features/servers/format';
import { pathLabels } from '@/features/servers/mesh-status';
import { RemovePendingButton } from '@/features/servers/remove-pending';
import { roleLabels } from '@/features/servers/server-status';
import { approve, create, index, show } from '@/routes/servers';
import { useLiveProps } from '@/hooks/use-live-props';
import { cn } from '@/lib/utils';

type Props = {
    enrollment: App.Data.Servers.EnrollmentData;
    command: string | null;
    controlPlaneUrl: string;
    machine: string | null;
    canApprove: boolean;
    canCancel: boolean;
    /** The team's other servers: the connected card shows the link to the dashboard's server. */
    otherServers?: number;
};

const POLL_MS = 2000;

/** Present-tense role words for "Ready to …". */
const readyWords: Record<App.Enums.ServerRole, string> = {
    runtime: 'run apps',
    edge: 'serve traffic',
    build: 'build',
    registry: 'store images',
};

function Check({
    state,
    children,
}: {
    state: 'done' | 'progress';
    children: ReactNode;
}) {
    return (
        <li className="flex items-center gap-2 type-small">
            {state === 'done' ? (
                <StatusIcon state="active" />
            ) : (
                <Spinner className="size-3.5 text-muted-foreground" />
            )}
            <span
                className={
                    state === 'done'
                        ? 'text-foreground'
                        : 'text-muted-foreground'
                }
            >
                {children}
            </span>
        </li>
    );
}

/**
 * The magic moment (flows §10 step 5): the dashboard's server and the new
 * one, joined by the private network line once it's up, and what's ready.
 */
function Proof({
    server,
    otherServers,
}: {
    server: App.Data.Servers.ServerData;
    otherServers: number;
}) {
    const mesh = server.mesh;
    const meshExpected = otherServers > 0 && !server.isControlPlane;
    const path = mesh?.state === 'connected' ? mesh.pathToControlPlane : null;
    const online = server.status === 'online';
    const roles = server.roles.map((role) => readyWords[role]).filter(Boolean);

    return (
        <div className="flex w-full max-w-md flex-col gap-4">
            {meshExpected && (
                <div
                    className="flex items-center gap-3"
                    aria-label={
                        path
                            ? `${server.name} reaches the dashboard’s server ${path === 'direct' ? 'directly' : 'through a relay'}`
                            : `${server.name} is joining the private network`
                    }
                >
                    <span className="flex shrink-0 items-center gap-2 rounded-lg border bg-card px-2.5 py-1.5 type-small">
                        <LayoutDashboard
                            aria-hidden="true"
                            className="size-3.5 text-muted-foreground"
                        />
                        Dashboard
                    </span>
                    <span className="relative flex flex-1 items-center">
                        <span
                            aria-hidden="true"
                            className={cn(
                                'h-0 w-full border-t-[1.5px] border-(--signal)',
                                path === 'relay' && 'border-dashed',
                                path === null && 'border-dotted opacity-60',
                            )}
                        />
                        <span className="absolute left-1/2 -translate-x-1/2 rounded-full border bg-card px-2 type-small whitespace-nowrap text-muted-foreground">
                            {path === null ? 'Connecting…' : pathLabels[path]}
                        </span>
                    </span>
                    <span className="shrink-0 rounded-lg border bg-card px-2.5 py-1.5 type-small">
                        {server.name}
                    </span>
                </div>
            )}
            <ul className="flex flex-col gap-1.5 text-left">
                <Check state={online ? 'done' : 'progress'}>
                    Reports to the dashboard every few seconds
                </Check>
                {meshExpected && (
                    <Check state={path ? 'done' : 'progress'}>
                        {path
                            ? `On the private network, ${pathLabels[path].toLowerCase()}`
                            : 'Joining the private network'}
                    </Check>
                )}
                {roles.length > 0 && (
                    <Check state={online ? 'done' : 'progress'}>
                        Ready to {roles.join(roles.length > 2 ? ', ' : ' and ')}
                    </Check>
                )}
            </ul>
        </div>
    );
}

function Connected({
    server,
    canApprove,
    otherServers,
}: {
    server: App.Data.Servers.ServerData;
    canApprove: boolean;
    otherServers: number;
}) {
    const { state, label } = serverStatus[server.status];
    const kind = deviceKind(server);
    const looksLike =
        server.device?.model && server.device.model !== 'Container'
            ? server.device.model
            : DEVICE_LABEL[kind];
    const facts = [looksLike, ...specParts(server.specs)];
    const waiting = server.status === 'pending_approval';

    return (
        <div
            role="status"
            aria-live="polite"
            className="flex flex-col items-center gap-4 rounded-xl bg-recessed px-6 py-8 text-center motion-safe:animate-enter"
        >
            <DeviceImage
                device={kind}
                status={deviceStatus(server.status)}
                size={220}
            />
            <div className="flex flex-col items-center gap-2">
                <div className="flex items-center gap-2">
                    <span className="type-heading">{server.name}</span>
                    <StatusPill state={state} label={label} />
                </div>
                <MetaLine className="justify-center type-small text-muted-foreground">
                    {facts}
                </MetaLine>
            </div>
            {waiting ? (
                canApprove ? (
                    <div className="flex flex-col items-center gap-2">
                        <p className="max-w-[48ch] type-small text-muted-foreground">
                            It connected and is waiting for your approval.{' '}
                            <Link
                                href={show(server.id)}
                                className="text-signal underline-offset-4 hover:underline"
                            >
                                Compare its key
                            </Link>{' '}
                            first if you like.
                        </p>
                        <ConfirmAction
                            trigger="Approve"
                            title={`Approve ${server.name}?`}
                            description="It starts receiving apps and tasks from this dashboard right away."
                            confirmLabel="Approve server"
                            form={approve.form(server.id)}
                        />
                    </div>
                ) : (
                    <p className="type-small text-muted-foreground">
                        An admin needs to approve it before apps run there.
                    </p>
                )
            ) : (
                <>
                    <Proof server={server} otherServers={otherServers} />
                    <div className="flex flex-wrap justify-center gap-2">
                        <Button asChild>
                            <Link href={show(server.id)}>
                                Open {server.name}
                            </Link>
                        </Button>
                        <Button asChild variant="ghost">
                            <Link href={index()}>All servers</Link>
                        </Button>
                    </div>
                </>
            )}
        </div>
    );
}

/**
 * Add a server, step 3 (flows §10): one command with a platform switcher,
 * then a live wait that turns into the detected device when the server
 * connects, with approval in place when it's required.
 */
export default function Enrollment({
    enrollment,
    command: sentCommand,
    controlPlaneUrl,
    machine,
    canApprove,
    canCancel,
    otherServers = 0,
}: Props) {
    // The join token is only sent on the first view; keep the command for this page's lifetime.
    const [command] = useState(sentCommand);
    const server = enrollment.server;
    const waiting =
        (server === null && !enrollment.expired) ||
        server?.status === 'pending_approval';
    // After approval, keep watching until the private network link is up.
    const joining =
        server !== null &&
        server.status === 'online' &&
        otherServers > 0 &&
        !server.isControlPlane &&
        server.mesh?.state !== 'connected';
    const picked = findMachine(machine);

    useLiveProps(['enrollment', 'canApprove'], {
        while: waiting || joining,
        everyMs: POLL_MS,
    });

    const expires = new Date(enrollment.expiresAt).toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
    });

    return (
        <>
            <Head title="Add a server" />

            <Page size="narrow">
                <PageHeader
                    title="Add a server"
                    description="Any Linux machine or Mac with Docker. It connects out, so there’s nothing to open."
                />

                <Wizard
                    steps={ADD_SERVER_STEPS.map((step, i) => ({
                        ...step,
                        summary:
                            i === 0
                                ? (picked?.label ?? 'Any machine')
                                : i === 1
                                  ? enrollment.roles
                                        .map((role) => roleLabels[role])
                                        .join(', ')
                                  : undefined,
                    }))}
                    current={2}
                >
                    {server !== null ? (
                        <Connected
                            server={server}
                            canApprove={canApprove}
                            otherServers={otherServers}
                        />
                    ) : enrollment.expired ? (
                        <div className="flex flex-col items-center gap-4 rounded-xl bg-recessed px-6 py-8 text-center">
                            <DeviceImage device="unknown" size={160} />
                            <p className="type-body">
                                This install link expired before a server used
                                it.
                            </p>
                            <Button asChild>
                                <Link href={create()}>Get a new command</Link>
                            </Button>
                        </div>
                    ) : (
                        <>
                            <div className="flex flex-col gap-1">
                                <h2 className="type-heading">
                                    Run this on the server
                                </h2>
                                <p className="type-small text-muted-foreground">
                                    {command
                                        ? `The link works once and expires at ${expires}.${picked?.value === 'mac' ? ' Run it in Terminal on the Mac.' : ''}`
                                        : 'The command was shown once. If you lost it, get a new one.'}
                                </p>
                            </div>
                            {command ? (
                                <InstallCommand
                                    command={command}
                                    machine={picked?.value ?? null}
                                />
                            ) : (
                                <div>
                                    <Button asChild variant="outline">
                                        <Link href={create()}>
                                            Get a new command
                                        </Link>
                                    </Button>
                                </div>
                            )}
                            <LiveWait
                                media={
                                    <DeviceImage
                                        device={picked?.device ?? 'unknown'}
                                        status="pending"
                                        size={180}
                                    />
                                }
                                status="Waiting for your server…"
                                hint="This page updates automatically, usually 20 to 40 seconds after you run the command."
                                fallback={
                                    <span>
                                        Not connecting? The server needs to
                                        reach{' '}
                                        <span className="type-mono-small text-foreground">
                                            {controlPlaneUrl}
                                        </span>{' '}
                                        over HTTPS.
                                    </span>
                                }
                            />
                        </>
                    )}
                </Wizard>

                {canCancel && server === null && (
                    <div className="flex flex-col gap-3 border-t pt-6 sm:flex-row sm:items-center sm:justify-between">
                        <div className="flex flex-col gap-0.5">
                            <p className="type-body font-medium">
                                Changed your mind?
                            </p>
                            <p className="type-small text-muted-foreground">
                                Remove this server and the command stops
                                working.
                            </p>
                        </div>
                        <RemovePendingButton
                            target={{
                                kind: 'enrollment',
                                id: enrollment.id,
                                name: enrollment.name,
                            }}
                            label="Remove server"
                        />
                    </div>
                )}
            </Page>
        </>
    );
}

Enrollment.layout = {
    breadcrumbs: [
        { title: 'Servers', href: index() },
        { title: 'Add server', href: create() },
    ],
};
