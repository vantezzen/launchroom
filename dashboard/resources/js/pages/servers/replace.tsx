import { Form, Head, Link, router } from '@inertiajs/react';
import { DeviceImage } from '@/components/devices/device-image';
import type { DeviceKind } from '@/components/devices/device-image';
import { ChoiceTiles } from '@/components/patterns/choice-tiles';
import { Page, PageHeader } from '@/components/patterns/page';
import { Section } from '@/components/patterns/section';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { deviceStatus } from '@/features/servers/format';
import { roleLabels, ServerStatus } from '@/features/servers/server-status';
import { create, index, show } from '@/routes/servers';
import { store } from '@/routes/servers/replace';
import {
    ReplacementProgress,
    snapshotAge,
} from '@/features/servers/replacement-progress';

type Props = {
    server: App.Data.Servers.ReplaceableServerData;
    candidates: App.Data.Servers.ReplaceableServerData[];
    plan: App.Data.Servers.ReplacementPlanItemData[];
    replacement: App.Data.Servers.ServerReplacementData | null;
    blockedReason: string | null;
    canEnroll: boolean;
    devices?: Record<string, string>;
};

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
                    <ReplacementProgress
                        replacement={replacement}
                        oldDevice={kind(server.id)}
                        newDevice={kind(replacement.newServerId)}
                        serving={server.roles.includes('edge')}
                    />
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
                                                                ? `Latest backup, ${snapshotAge(item.backupTakenAt)} (snapshot ${item.snapshot})`
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
