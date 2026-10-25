import { Form, Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Page, PageHeader } from '@/components/patterns/page';
import type { StatusState } from '@/components/status';
import { Status } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { ProgressRing, StepCard } from '@/features/onboarding/step-card';
import { ReplacementProgress } from '@/pages/servers/replace';
import { finish, rebuild, replace, show } from '@/routes/recovery';
import { show as serverShow } from '@/routes/servers';

type Server = App.Data.Servers.ReplaceableServerData;

type Props = {
    active: boolean;
    startedAt: string | null;
    previous: Server | null;
    current: Server | null;
    plan: App.Data.Servers.ReplacementPlanItemData[];
    replacement: App.Data.Servers.ServerReplacementData | null;
    dns: { label: string; check: App.Data.Domains.DnsCheckData }[];
    dashboardHost: string | null;
    expectedAddresses: string[];
    servers: {
        id: string;
        name: string;
        status: App.Enums.ServerStatus;
        lastSeenAt: string | null;
        reconnected: boolean;
    }[];
    images: {
        checked: number;
        unknown: number;
        missing: { service: string; deployment: string; commit: string }[];
    } | null;
};

const dnsStates: Record<string, StatusState> = {
    ok: 'active',
    missing: 'warning',
    mismatch: 'failed',
    unknown: 'warning',
    error: 'warning',
};

/**
 * Recovery mode (docs/14 §5.2): after `install.sh --restore`, replace the
 * previous control-plane server, check DNS, follow the restores and
 * re-placements, rebuild images the registry lost, then finish.
 */
export default function Recovery({
    active,
    startedAt,
    previous,
    current,
    plan,
    replacement,
    dns,
    dashboardHost,
    expectedAddresses,
    servers,
    images,
}: Props) {
    const replaced =
        previous === null ||
        previous.status === 'revoked' ||
        replacement !== null;
    const restoresDone =
        replacement !== null && replacement.status !== 'running';
    const dnsOk = dns.every((item) => item.check.status === 'ok');
    const imagesOk = images !== null && images.missing.length === 0;
    const open = [
        ...(replaced ? [] : ['replace']),
        ...(dnsOk ? [] : ['dns']),
        ...(replacement === null || restoresDone ? [] : ['restores']),
        ...(imagesOk ? [] : ['images']),
    ];
    const serversOk = servers.every((server) => server.reconnected);
    const doneCount = [replaced, dnsOk, imagesOk, serversOk].filter(
        Boolean,
    ).length;
    const firstOpen =
        [
            [replaced, 'replace'],
            [dnsOk, 'dns'],
            [imagesOk, 'images'],
            [serversOk, 'servers'],
        ].find(([done]) => !done)?.[1] ?? null;
    const [openId, setOpenId] = useState<string | null>(
        firstOpen as string | null,
    );
    const restorable = plan.filter(
        (item) => item.stateful && item.snapshot !== null,
    );

    return (
        <>
            <Head title="Recovery" />

            <Page size="narrow" className="gap-8">
                <PageHeader
                    variant="display"
                    title={active ? 'Let’s bring everything back.' : 'Recovery'}
                    description={
                        active ? (
                            <>
                                launchroom was restored from a backup
                                {startedAt && (
                                    <>
                                        {' '}
                                        <TimeAgo value={startedAt} />
                                    </>
                                )}
                                . Your other servers kept running.
                            </>
                        ) : (
                            'Recovery mode is not active.'
                        )
                    }
                    actions={
                        active ? (
                            <ProgressRing
                                done={doneCount}
                                total={4}
                                size={64}
                            />
                        ) : undefined
                    }
                    className="flex-row items-center justify-between"
                />

                <ol className="flex flex-col gap-3">
                    <StepCard
                        id="replace"
                        number={1}
                        status={replaced ? 'done' : 'pending'}
                        title="Replace the old control-plane server"
                        line={
                            replaced
                                ? 'Done'
                                : previous
                                  ? `Its workloads and backups move to ${current?.name ?? 'this server'}.`
                                  : undefined
                        }
                        open={openId === 'replace'}
                        onOpen={() => setOpenId('replace')}
                    >
                        {previous === null ? (
                            <p className="type-body text-muted-foreground">
                                The backup didn’t record a previous
                                control-plane server.
                            </p>
                        ) : replacement !== null ? (
                            <ReplacementProgress replacement={replacement} />
                        ) : previous.status === 'revoked' ? (
                            <p className="type-body text-muted-foreground">
                                {previous.name} was already revoked.
                            </p>
                        ) : current === null ? (
                            <p className="type-body text-muted-foreground">
                                This server’s launchroomd hasn’t enrolled yet.
                                Check `./launchroom status` on the new server.
                            </p>
                        ) : (
                            <>
                                <p className="max-w-[65ch] type-body">
                                    <Link
                                        href={serverShow(previous.id)}
                                        className="underline underline-offset-4"
                                    >
                                        {previous.name}
                                    </Link>{' '}
                                    ran the old control plane. {current.name}{' '}
                                    inherits its roles and labels, its keys are
                                    revoked, its workloads are placed here again
                                    and{' '}
                                    {restorable.length === 0
                                        ? 'no database or volume has a backup to restore'
                                        : `${restorable.length} ${restorable.length === 1 ? 'database or volume is' : 'databases and volumes are'} restored from their latest backups`}
                                    .
                                </p>
                                {plan.length > 0 && (
                                    <ul className="flex flex-col gap-1 type-small text-muted-foreground">
                                        {plan.map((item) => (
                                            <li key={item.instanceId}>
                                                {item.name}:{' '}
                                                {!item.stateful
                                                    ? 're-placed'
                                                    : item.snapshot !== null
                                                      ? `restore snapshot ${item.snapshot}`
                                                      : (item.reason ??
                                                        'starts empty')}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                <Form {...replace.form()}>
                                    {({ processing, errors }) => (
                                        <div className="flex flex-col gap-2">
                                            <div>
                                                <Button
                                                    type="submit"
                                                    disabled={processing}
                                                >
                                                    {processing && <Spinner />}
                                                    Replace {
                                                        previous.name
                                                    } with{' '}
                                                    {current.name}
                                                </Button>
                                            </div>
                                            {errors.replace && (
                                                <p className="type-small text-destructive">
                                                    {errors.replace}
                                                </p>
                                            )}
                                        </div>
                                    )}
                                </Form>
                            </>
                        )}
                    </StepCard>

                    <StepCard
                        id="dns"
                        number={2}
                        status={dnsOk ? 'done' : 'pending'}
                        title="Point DNS at this server"
                        line={
                            dnsOk
                                ? 'Every hostname points here'
                                : expectedAddresses.join(', ') || undefined
                        }
                        open={openId === 'dns'}
                        onOpen={() => setOpenId('dns')}
                    >
                        <p className="max-w-[65ch] type-body text-muted-foreground">
                            {expectedAddresses.length > 0
                                ? `Point ${dashboardHost ?? 'the dashboard'} and your apps domain at ${expectedAddresses.join(', ')}. If the hostname stays the same, your servers reconnect automatically.`
                                : 'No edge server has a public address yet.'}
                        </p>
                        {dns.length === 0 ? (
                            <p className="type-small text-muted-foreground">
                                No public hostname to check.
                            </p>
                        ) : (
                            <ul className="flex flex-col gap-2">
                                {dns.map((item) => (
                                    <li
                                        key={item.label}
                                        className="flex flex-col"
                                    >
                                        <div className="flex flex-wrap items-center gap-3">
                                            <Status
                                                state={
                                                    dnsStates[
                                                        item.check.status
                                                    ] ?? 'warning'
                                                }
                                                label={item.label}
                                            />
                                            <span className="type-mono-small text-muted-foreground">
                                                {item.check.hostname}
                                            </span>
                                        </div>
                                        <span className="type-small text-muted-foreground">
                                            {item.check.message}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <div>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => router.reload({ only: ['dns'] })}
                            >
                                Check again
                            </Button>
                        </div>
                    </StepCard>

                    <StepCard
                        id="images"
                        number={3}
                        status={imagesOk ? 'done' : 'pending'}
                        title="Rebuild lost images"
                        line={
                            imagesOk
                                ? 'All images are in the registry'
                                : images === null
                                  ? 'Not checked yet'
                                  : `${images.missing.length} missing`
                        }
                        open={openId === 'images'}
                        onOpen={() => setOpenId('images')}
                    >
                        <p className="max-w-[65ch] type-body text-muted-foreground">
                            Unless the registry kept its images on S3, they’re
                            gone. Services are rebuilt from their recorded
                            commits.
                        </p>
                        {images === null ? (
                            <div>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        router.visit(
                                            show({ query: { images: 1 } }),
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Check the registry
                                </Button>
                            </div>
                        ) : images.missing.length === 0 ? (
                            <p className="type-small text-muted-foreground">
                                All {images.checked} active images are in the
                                registry
                                {images.unknown > 0 &&
                                    ` (${images.unknown} could not be checked)`}
                                .
                            </p>
                        ) : (
                            <>
                                <ul className="flex flex-col gap-1 type-small">
                                    {images.missing.map((item) => (
                                        <li key={item.deployment}>
                                            {item.service}{' '}
                                            <span className="type-mono-small text-muted-foreground">
                                                {item.commit}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                                <Form {...rebuild.form()}>
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            size="sm"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            Rebuild {images.missing.length}{' '}
                                            {images.missing.length === 1
                                                ? 'image'
                                                : 'images'}
                                        </Button>
                                    )}
                                </Form>
                            </>
                        )}
                    </StepCard>

                    <StepCard
                        id="servers"
                        number={4}
                        status={serversOk ? 'done' : 'pending'}
                        title="Reconnect your other servers"
                        line={
                            serversOk
                                ? servers.length === 0
                                    ? 'No other servers'
                                    : 'All reconnected'
                                : `${servers.filter((server) => !server.reconnected).length} not seen yet`
                        }
                        open={openId === 'servers'}
                        onOpen={() => setOpenId('servers')}
                    >
                        {servers.length === 0 ? (
                            <p className="type-body text-muted-foreground">
                                There are no other servers.
                            </p>
                        ) : (
                            <ul className="flex flex-col gap-2">
                                {servers.map((server) => (
                                    <li
                                        key={server.id}
                                        className="flex flex-wrap items-center justify-between gap-2"
                                    >
                                        <Status
                                            state={
                                                server.reconnected
                                                    ? 'active'
                                                    : 'warning'
                                            }
                                            label={server.name}
                                        />
                                        <span className="type-small text-muted-foreground">
                                            {server.reconnected
                                                ? 'Reconnected, last seen'
                                                : 'Not seen since the restore. If the dashboard URL changed, set LR_CONTROL_PLANE_URL in launchroomd/.env on that server and restart launchroomd. Last seen'}{' '}
                                            <TimeAgo
                                                value={server.lastSeenAt}
                                            />
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </StepCard>
                </ol>

                {active && (
                    <Form
                        {...finish.form()}
                        transform={(data) => ({ ...data, open })}
                    >
                        {({ processing }) => (
                            <div className="flex flex-wrap items-center gap-3">
                                <Button
                                    type="submit"
                                    variant={
                                        open.length === 0
                                            ? 'default'
                                            : 'outline'
                                    }
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    Finish recovery
                                </Button>
                                {open.length > 0 && (
                                    <span className="type-small text-muted-foreground">
                                        Some steps are still open. Finishing now
                                        records that in the audit log.
                                    </span>
                                )}
                            </div>
                        )}
                    </Form>
                )}
            </Page>
        </>
    );
}

Recovery.layout = {
    breadcrumbs: [{ title: 'Recovery', href: show() }],
};
