import { Form, Head, Link, router } from '@inertiajs/react';
import { DeviceImage } from '@/components/devices/device-image';
import type { DeviceKind } from '@/components/devices/device-image';
import { ChoiceTiles } from '@/components/patterns/choice-tiles';
import { Page, PageHeader } from '@/components/patterns/page';
import { Section } from '@/components/patterns/section';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import type { StatusState } from '@/components/status';
import { Status } from '@/components/status';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { deviceStatus } from '@/features/servers/format';
import { roleLabels, ServerStatus } from '@/features/servers/server-status';
import { create, index, show } from '@/routes/servers';
import { store } from '@/routes/servers/replace';

type Props = {
    server: App.Data.Servers.ReplaceableServerData;
    candidates: App.Data.Servers.ReplaceableServerData[];
    plan: App.Data.Servers.ReplacementPlanItemData[];
    replacement: App.Data.Servers.ServerReplacementData | null;
    blockedReason: string | null;
    canEnroll: boolean;
    devices?: Record<string, string>;
};

const serviceStates: Record<string, [StatusState, string]> = {
    pending: ['progress', 'Waiting'],
    deploying: ['progress', 'Starting on the replacement'],
    restoring: ['progress', 'Restoring from backup'],
    restored: ['active', 'Restored'],
    done: ['active', 'Running'],
    running: ['active', 'Already running elsewhere'],
    failed: ['failed', 'Needs attention'],
    gone: ['idle', 'Removed meanwhile'],
};

function age(value: string | null): string {
    if (value === null) {
        return 'unknown age';
    }

    const hours = Math.max(
        0,
        Math.round((Date.now() - new Date(value).getTime()) / 36e5),
    );

    return hours < 1
        ? 'less than an hour old'
        : hours < 48
          ? `${hours} h old`
          : `${Math.round(hours / 24)} days old`;
}

export function ReplacementProgress({
    replacement,
}: {
    replacement: App.Data.Servers.ServerReplacementData;
}) {
    const running = replacement.status === 'running';

    useEffect(() => {
        if (!running) {
            return;
        }

        const timer = window.setInterval(
            () => router.reload({ only: ['replacement', 'server'] }),
            3000,
        );

        return () => window.clearInterval(timer);
    }, [running]);

    return (
        <section
            className="flex flex-col gap-4"
            aria-labelledby="replacement-progress"
        >
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <h2 id="replacement-progress" className="type-heading">
                    {replacement.newServer} replaces {replacement.oldServer}
                </h2>
                <Status
                    state={
                        running
                            ? 'progress'
                            : replacement.error === null
                              ? 'active'
                              : 'warning'
                    }
                    label={
                        running
                            ? 'In progress'
                            : replacement.error === null
                              ? 'Done'
                              : 'Done with problems'
                    }
                />
            </div>
            <p className="max-w-[65ch] type-body text-muted-foreground">
                Roles and labels moved to {replacement.newServer}, and{' '}
                {replacement.oldServer}’s key was revoked. If it comes back
                online, it stops its apps. Databases and volumes are restored
                first, then the services that ran there start.
            </p>
            {replacement.error !== null && (
                <p className="type-body">{replacement.error}</p>
            )}
            {replacement.services.length === 0 ? (
                <p className="type-body text-muted-foreground">
                    Nothing ran on {replacement.oldServer}.
                </p>
            ) : (
                <ul className="flex flex-col divide-y">
                    {replacement.services.map((service) => {
                        const [state, label] = serviceStates[
                            service.status
                        ] ?? ['progress', service.status];

                        return (
                            <li
                                key={service.instance}
                                className="flex flex-col gap-1 py-3"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="type-body">
                                        {service.name}
                                    </span>
                                    <Status state={state} label={label} />
                                </div>
                                <span className="type-small text-muted-foreground">
                                    {service.kind === 'stateful'
                                        ? service.restore !== null
                                            ? `Restoring snapshot ${service.restore.snapshot} (${age(service.restore.takenAt)})`
                                            : 'Not restored: starts empty'
                                        : 'Re-placed after the data is back'}
                                </span>
                                {service.message !== null && (
                                    <span className="type-mono-small break-words text-muted-foreground">
                                        {service.message}
                                    </span>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}
        </section>
    );
}

/**
 * Servers → (dead server) → Replace server (docs/14 §5.3): pick a
 * replacement, choose which databases and volumes to restore from their
 * latest backups, follow the progress.
 */
export default function ReplaceServer({
    server,
    candidates,
    plan,
    replacement,
    blockedReason,
    canEnroll,
    devices = {},
}: Props) {
    const [target, setTarget] = useState(candidates[0]?.id ?? '');
    const [restore, setRestore] = useState<string[]>(
        plan
            .filter((item) => item.stateful && item.snapshot !== null)
            .map((item) => item.instanceId),
    );
    const stateful = plan.filter((item) => item.stateful);
    const stateless = plan.filter((item) => !item.stateful);
    const chosen = candidates.find((candidate) => candidate.id === target);
    const kind = (id: string): DeviceKind =>
        (devices[id] as DeviceKind | undefined) ?? 'unknown';

    return (
        <>
            <Head title={`Replace ${server.name}`} />

            <Page size="narrow">
                <PageHeader
                    title={`Replace ${server.name}`}
                    media={
                        <div className="flex size-20 items-center justify-center rounded-xl border bg-recessed">
                            <DeviceImage
                                device={kind(server.id)}
                                status={deviceStatus(server.status)}
                                size={72}
                            />
                        </div>
                    }
                    description={
                        <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
                            <ServerStatus
                                status={server.status}
                                lastSeenAt={server.lastSeenAt}
                            />
                            <span>
                                {server.roles
                                    .map((role) => roleLabels[role])
                                    .join(', ')}
                            </span>
                        </span>
                    }
                />

                {replacement !== null && (
                    <ReplacementProgress replacement={replacement} />
                )}

                {replacement === null && blockedReason !== null && (
                    <div className="flex flex-col items-start gap-4 rounded-xl border bg-card p-5 shadow-card">
                        <p className="max-w-[65ch] type-body">
                            {blockedReason}
                        </p>
                        <Button asChild variant="outline" size="sm">
                            <Link href={show(server.id)}>
                                Back to {server.name}
                            </Link>
                        </Button>
                    </div>
                )}

                {replacement === null && blockedReason === null && (
                    <Form
                        {...store.form(server.id)}
                        transform={(data) => ({
                            ...data,
                            replacement: target,
                            restore,
                        })}
                        className="flex flex-col gap-10"
                    >
                        {({ processing, errors }) => (
                            <>
                                <Section
                                    title="1. Which server takes over?"
                                    description={`It gets ${server.name}’s roles and labels.`}
                                >
                                    {candidates.length === 0 ? (
                                        <p className="type-body text-muted-foreground">
                                            No other server is online yet.
                                        </p>
                                    ) : (
                                        <ChoiceTiles
                                            label="Replacement server"
                                            columns={3}
                                            value={target || null}
                                            onChange={setTarget}
                                            options={candidates.map(
                                                (candidate) => ({
                                                    value: candidate.id,
                                                    label: candidate.name,
                                                    description: candidate.roles
                                                        .map(
                                                            (role) =>
                                                                roleLabels[
                                                                    role
                                                                ],
                                                        )
                                                        .join(', '),
                                                    media: (
                                                        <DeviceImage
                                                            device={kind(
                                                                candidate.id,
                                                            )}
                                                            size={110}
                                                        />
                                                    ),
                                                }),
                                            )}
                                            other={
                                                canEnroll
                                                    ? {
                                                          label: 'Add a new server',
                                                          description:
                                                              'It shows up here once it’s online.',
                                                          onSelect: () =>
                                                              router.visit(
                                                                  create(),
                                                              ),
                                                      }
                                                    : undefined
                                            }
                                        />
                                    )}
                                    <InputError message={errors.replacement} />
                                </Section>

                                <Section
                                    title="2. Data"
                                    description="Databases and volumes come back from their latest backup."
                                >
                                    {stateful.length === 0 ? (
                                        <p className="type-body text-muted-foreground">
                                            No databases or volumes lived on{' '}
                                            {server.name}.
                                        </p>
                                    ) : (
                                        <ul className="flex flex-col divide-y rounded-xl border bg-card shadow-card">
                                            {stateful.map((item) => (
                                                <li
                                                    key={item.instanceId}
                                                    className="flex items-start gap-3 px-5 py-4"
                                                >
                                                    <Checkbox
                                                        id={`restore-${item.instanceId}`}
                                                        className="mt-0.5"
                                                        disabled={
                                                            item.snapshot ===
                                                            null
                                                        }
                                                        checked={restore.includes(
                                                            item.instanceId,
                                                        )}
                                                        onCheckedChange={(
                                                            checked,
                                                        ) =>
                                                            setRestore(
                                                                (current) =>
                                                                    checked ===
                                                                    true
                                                                        ? [
                                                                              ...current,
                                                                              item.instanceId,
                                                                          ]
                                                                        : current.filter(
                                                                              (
                                                                                  id,
                                                                              ) =>
                                                                                  id !==
                                                                                  item.instanceId,
                                                                          ),
                                                            )
                                                        }
                                                    />
                                                    <Label
                                                        htmlFor={`restore-${item.instanceId}`}
                                                        className="flex flex-col gap-0.5 font-normal"
                                                    >
                                                        <span className="type-body font-medium">
                                                            {item.name}
                                                        </span>
                                                        <span className="type-small text-muted-foreground">
                                                            {item.snapshot !==
                                                            null
                                                                ? `Latest backup, ${age(item.backupTakenAt)} (snapshot ${item.snapshot})`
                                                                : (item.reason ??
                                                                  'No backup: it starts empty.')}
                                                        </span>
                                                    </Label>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </Section>

                                <Section title="3. Everything else">
                                    <p className="type-body text-muted-foreground">
                                        {stateless.length === 0
                                            ? `No other services ran on ${server.name}.`
                                            : `${stateless.map((item) => item.name).join(', ')} ${stateless.length === 1 ? 'starts' : 'start'} on your other servers once the data is back.`}
                                    </p>
                                </Section>

                                <div className="flex flex-col gap-4 rounded-xl border bg-card p-5 shadow-card sm:flex-row sm:items-center">
                                    <p className="min-w-0 flex-1 type-small text-muted-foreground">
                                        {chosen
                                            ? `${server.name}’s key is revoked. If it comes back online, it stops its apps. Its disk isn’t touched.`
                                            : 'Pick a server first.'}
                                    </p>
                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        disabled={processing || target === ''}
                                    >
                                        {processing && <Spinner />}
                                        Replace {server.name}
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                )}
            </Page>
        </>
    );
}

ReplaceServer.layout = {
    breadcrumbs: [{ title: 'Servers', href: index() }],
};
