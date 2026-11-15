import { Form, Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Page, PageHeader } from '@/components/patterns/page';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { DoneRing, StepCard } from '@/components/steps/step-card';
import {
    CutoverDnsStep,
    CutoverImagesStep,
    CutoverServersStep,
} from '@/features/moves/cutover-steps';
import { ReplacementProgress } from '@/features/servers/replacement-progress';
import { finish, rebuild, replace, show } from '@/routes/recovery';
import { show as serverShow } from '@/routes/servers';

type Props = App.Data.Instance.RecoveryChecklistData;

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
    dashboardHost,
    checks,
    openSteps,
}: Props) {
    const replaced = !openSteps.includes('replace');
    const doneCount = [
        replaced,
        checks.dnsOk,
        checks.imagesOk,
        checks.serversOk,
    ].filter(Boolean).length;
    const [openId, setOpenId] = useState<string | null>(
        openSteps.find((step) => step !== 'restores') ?? null,
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
                            <DoneRing done={doneCount} total={4} size={64} />
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

                    <CutoverDnsStep
                        checks={checks}
                        number={2}
                        open={openId === 'dns'}
                        onOpen={() => setOpenId('dns')}
                        onCheck={() => router.reload({ only: ['checks'] })}
                    >
                        <p className="max-w-[65ch] type-body text-muted-foreground">
                            {checks.expectedAddresses.length > 0
                                ? `Point ${dashboardHost ?? 'the dashboard'} and your apps domain at ${checks.expectedAddresses.join(', ')}. If the hostname stays the same, your servers reconnect automatically.`
                                : 'No edge server has a public address yet.'}
                        </p>
                    </CutoverDnsStep>

                    <CutoverImagesStep
                        checks={checks}
                        number={3}
                        open={openId === 'images'}
                        onOpen={() => setOpenId('images')}
                        onCheck={() =>
                            router.visit(show({ query: { images: 1 } }), {
                                preserveScroll: true,
                            })
                        }
                        title="Rebuild lost images"
                        rebuild={rebuild.form()}
                    >
                        Unless the registry kept its images on S3, they’re gone.
                        Services are rebuilt from their recorded commits.
                    </CutoverImagesStep>

                    <CutoverServersStep
                        checks={checks}
                        number={4}
                        open={openId === 'servers'}
                        onOpen={() => setOpenId('servers')}
                        title="Reconnect your other servers"
                        notSeen="Not seen since the restore. If the dashboard URL changed, set LR_CONTROL_PLANE_URL in launchroomd/.env on that server and restart launchroomd."
                    />
                </ol>

                {active && (
                    <Form {...finish.form()}>
                        {({ processing }) => (
                            <div className="flex flex-wrap items-center gap-3">
                                <Button
                                    type="submit"
                                    variant={
                                        openSteps.length === 0
                                            ? 'default'
                                            : 'outline'
                                    }
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    Finish recovery
                                </Button>
                                {openSteps.length > 0 && (
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
