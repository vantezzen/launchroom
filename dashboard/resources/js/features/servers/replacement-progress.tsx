import { Status } from '@/components/status';
import { useLiveProps } from '@/hooks/use-live-props';
import { vocabulary } from '@/lib/vocabulary';

/** A snapshot's age: "3 h old", "2 days old". */
export function snapshotAge(value: string | null): string {
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

    useLiveProps(['replacement', 'server'], { while: running, everyMs: 3000 });

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
                        const { tone, label } =
                            vocabulary.ReplacedServiceStatus[service.status];

                        return (
                            <li
                                key={service.instance}
                                className="flex flex-col gap-1 py-3"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="type-body">
                                        {service.name}
                                    </span>
                                    <Status state={tone} label={label} />
                                </div>
                                <span className="type-small text-muted-foreground">
                                    {service.kind === 'stateful'
                                        ? service.restore !== null
                                            ? `Restoring snapshot ${service.restore.snapshot} (${snapshotAge(service.restore.takenAt)})`
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
