import { Form } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Status, dnsCheckStatus } from '@/components/status';
import { StepCard } from '@/components/steps/step-card';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type { RouteFormDefinition } from '@/wayfinder';

type Checks = App.Data.Moves.CutoverChecksData;

type StepProps = {
    checks: Checks;
    number: number;
    open: boolean;
    onOpen: () => void;
};

/** DNS of the dashboard, the apps wildcard and custom domains points at the new machine. */
export function CutoverDnsStep({
    checks,
    number,
    open,
    onOpen,
    onCheck,
    children,
}: StepProps & { onCheck: () => void; children?: ReactNode }) {
    const checked = checks.dns.some((record) => record.check !== null);

    return (
        <StepCard
            id="dns"
            number={number}
            status={checks.dnsOk ? 'done' : 'pending'}
            title="Point DNS at this server"
            line={
                checks.dnsOk
                    ? 'Every hostname points here'
                    : checks.expectedAddresses.join(', ') || undefined
            }
            open={open}
            onOpen={onOpen}
        >
            {children}
            {checks.dns.length === 0 ? (
                <p className="type-body text-muted-foreground">
                    No public hostname to check.
                </p>
            ) : (
                <ul className="flex flex-col gap-3">
                    {checks.dns.map((record) => (
                        <li key={record.host} className="flex flex-col gap-0.5">
                            <div className="flex flex-wrap items-center gap-3">
                                <Status
                                    state={
                                        record.check === null
                                            ? 'idle'
                                            : dnsCheckStatus[
                                                  record.check.status
                                              ].state
                                    }
                                    label={record.label}
                                />
                                <span className="type-mono-small break-all text-muted-foreground">
                                    {record.host}
                                </span>
                            </div>
                            <span className="type-small text-muted-foreground">
                                {record.check?.message ??
                                    `Point it at ${checks.expectedAddresses.join(', ')}.`}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
            <div>
                <Button variant="outline" size="sm" onClick={onCheck}>
                    {checked ? 'Check again' : 'Check DNS'}
                </Button>
            </div>
        </StepCard>
    );
}

/** Live images the new registry doesn't have, rebuilt from their recorded commits. */
export function CutoverImagesStep({
    checks,
    number,
    open,
    onOpen,
    onCheck,
    title,
    rebuild,
    children,
}: StepProps & {
    onCheck: () => void;
    title: string;
    rebuild: RouteFormDefinition<'post'>;
    /** Why images are missing on this machine. */
    children: ReactNode;
}) {
    const images = checks.images;

    return (
        <StepCard
            id="images"
            number={number}
            status={checks.imagesOk ? 'done' : 'pending'}
            title={title}
            line={
                checks.imagesOk
                    ? 'All images are in the registry'
                    : images === null
                      ? 'Not checked yet'
                      : `${images.missing.length} missing`
            }
            open={open}
            onOpen={onOpen}
        >
            <p className="max-w-[65ch] type-body text-muted-foreground">
                {children}
            </p>
            {images === null ? (
                <div>
                    <Button variant="outline" size="sm" onClick={onCheck}>
                        Check the registry
                    </Button>
                </div>
            ) : images.missing.length === 0 ? (
                <p className="type-small text-muted-foreground">
                    All {images.checked} running images are in the registry
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
                    <Form {...rebuild}>
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
    );
}

/** The team's other servers talked to the new machine since the cutover. */
export function CutoverServersStep({
    checks,
    number,
    open,
    onOpen,
    title,
    notSeen,
}: StepProps & {
    title: string;
    /** What to do about a server that hasn't reconnected. */
    notSeen: string;
}) {
    const missing = checks.servers.filter(
        (server) => !server.reconnected,
    ).length;

    return (
        <StepCard
            id="servers"
            number={number}
            status={checks.serversOk ? 'done' : 'pending'}
            title={title}
            line={
                checks.servers.length === 0
                    ? 'No other servers'
                    : checks.serversOk
                      ? 'All reconnected'
                      : `${missing} not seen yet`
            }
            open={open}
            onOpen={onOpen}
        >
            {checks.servers.length === 0 ? (
                <p className="type-body text-muted-foreground">
                    There are no other servers.
                </p>
            ) : (
                <ul className="flex flex-col gap-2">
                    {checks.servers.map((server) => (
                        <li key={server.id} className="flex flex-col gap-0.5">
                            <Status
                                state={
                                    server.reconnected ? 'active' : 'warning'
                                }
                                label={server.name}
                            />
                            <span className="type-small text-muted-foreground">
                                {server.reconnected
                                    ? 'Reconnected, last seen'
                                    : `${notSeen} Last seen`}{' '}
                                <TimeAgo value={server.lastSeenAt} />
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </StepCard>
    );
}
