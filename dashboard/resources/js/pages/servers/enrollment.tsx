import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { ConfirmAction } from '@/components/confirm-action';
import { DeviceImage, DEVICE_LABEL } from '@/components/devices/device-image';
import { LiveWait } from '@/components/patterns/live-wait';
import { Page, PageHeader } from '@/components/patterns/page';
import { Wizard } from '@/components/patterns/wizard';
import { StatusPill, serverStatus } from '@/components/status';
import { Button } from '@/components/ui/button';
import {
    ADD_SERVER_STEPS,
    InstallCommand,
    machine as findMachine,
} from '@/features/servers/add-server';
import { deviceKind, deviceStatus, specParts } from '@/features/servers/format';
import { RemovePendingButton } from '@/features/servers/remove-pending';
import { roleLabels } from '@/features/servers/server-status';
import { approve, create, index, show } from '@/routes/servers';

type Props = {
    enrollment: App.Data.Servers.EnrollmentData;
    joinToken: string | null;
    controlPlaneUrl: string;
    controlPlaneKey: string;
    machine: string | null;
    canApprove: boolean;
    canCancel: boolean;
};

const POLL_MS = 2000;

function Connected({
    server,
    canApprove,
}: {
    server: App.Data.Servers.ServerData;
    canApprove: boolean;
}) {
    const { state, label } = serverStatus[server.status];
    const kind = deviceKind(server);
    const looksLike =
        server.device?.model && server.device.model !== 'Container'
            ? server.device.model
            : DEVICE_LABEL[kind];
    const facts = [looksLike, ...specParts(server.specs)].join(' · ');
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
                <p className="type-small text-muted-foreground">{facts}</p>
            </div>
            {waiting ? (
                canApprove ? (
                    <div className="flex flex-col items-center gap-2">
                        <p className="max-w-[48ch] type-small text-muted-foreground">
                            It connected and waits for your approval.{' '}
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
                <div className="flex flex-wrap justify-center gap-2">
                    <Button asChild>
                        <Link href={show(server.id)}>Open {server.name}</Link>
                    </Button>
                    <Button asChild variant="ghost">
                        <Link href={index()}>All servers</Link>
                    </Button>
                </div>
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
    joinToken,
    controlPlaneUrl,
    controlPlaneKey,
    machine,
    canApprove,
    canCancel,
}: Props) {
    // The token is only sent on the first view; keep it for this page's lifetime.
    const [token] = useState(joinToken);
    const server = enrollment.server;
    const waiting =
        (server === null && !enrollment.expired) ||
        server?.status === 'pending_approval';
    const picked = findMachine(machine);

    useEffect(() => {
        if (!waiting) {
            return;
        }

        const timer = window.setInterval(() => {
            router.reload({ only: ['enrollment', 'canApprove'] });
        }, POLL_MS);

        return () => window.clearInterval(timer);
    }, [waiting]);

    const command = token
        ? `curl -fsSL ${controlPlaneUrl}/install/server.sh | LR_JOIN_TOKEN=${token} LR_CP_KEY=${controlPlaneKey} LR_CONTROL_PLANE_URL=${controlPlaneUrl} sh`
        : null;
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
                        <Connected server={server} canApprove={canApprove} />
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
                                hint="This updates by itself, usually 20 to 40 seconds after you run the command."
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
                                Remove this server; the command stops working.
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
