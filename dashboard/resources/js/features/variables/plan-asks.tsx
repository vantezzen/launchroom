import { Link, router } from '@inertiajs/react';
import { HardDrive, KeyRound, Plus, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import services from '@/routes/projects/services';
import { store as storeVolume } from '@/routes/projects/services/volumes';

type Plan = App.Data.Canvas.DetectedPlanData;
type Route = [project: string, environment: string, service: string];

/**
 * What a build plan asks of the user before the app works (docs/04 §4):
 * secrets launchroom can't generate (RAILS_MASTER_KEY), a Laravel app on a
 * SQLite file every deploy resets, and folders whose files should outlive a
 * deploy. With `route` (the service exists) each one is a link or a
 * one-click volume; without (a plan preview) they are listed.
 */
export function PlanAsks({
    plan,
    route,
    volumePaths = [],
    canUpdate = false,
}: {
    plan: Plan;
    route?: Route;
    /** Mount paths the service already has (their suggestions are left out). */
    volumePaths?: string[];
    canUpdate?: boolean;
}) {
    const [adding, setAdding] = useState<string | null>(null);
    const required = plan.requiredSecrets.filter((secret) =>
        plan.missingSecrets.includes(secret.name),
    );
    const volumes = plan.volumeSuggestions.filter(
        (suggestion) => !volumePaths.includes(suggestion.path),
    );

    if (
        required.length === 0 &&
        volumes.length === 0 &&
        plan.sqliteWarning === null &&
        plan.envAliases.length === 0
    ) {
        return null;
    }

    const variablesUrl = (name: string) =>
        route
            ? `${
                  services.section({
                      project: route[0],
                      environment: route[1],
                      service: route[2],
                      section: 'variables',
                  }).url
              }?add=${encodeURIComponent(name)}&type=secret`
            : null;

    const addVolume = (path: string) => {
        if (!route) {
            return;
        }

        setAdding(path);
        router.post(
            storeVolume(route).url,
            { mountPath: path },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setAdding(null),
            },
        );
    };

    return (
        <div className="flex flex-col gap-3">
            {required.length > 0 && (
                <div
                    className="flex flex-col gap-2 rounded-xl border bg-status-progress-soft/50 px-4 py-3"
                    aria-label="Needed before the first deploy"
                >
                    <span className="flex items-center gap-2 type-label">
                        <KeyRound
                            aria-hidden="true"
                            className="size-3.5 shrink-0 text-status-progress"
                        />
                        Needed before the first deploy
                    </span>
                    <ul className="flex flex-col gap-1.5">
                        {required.map((secret) => {
                            const url = canUpdate
                                ? variablesUrl(secret.name)
                                : null;

                            return (
                                <li
                                    key={secret.name}
                                    className="flex flex-wrap items-baseline gap-x-2 gap-y-1 type-small"
                                >
                                    <code className="type-mono-small">
                                        {secret.name}
                                    </code>
                                    {secret.reason && (
                                        <span className="text-muted-foreground">
                                            {secret.reason}
                                        </span>
                                    )}
                                    {url && (
                                        <Link
                                            href={url}
                                            className="text-signal underline-offset-4 hover:underline"
                                        >
                                            Add as a secret
                                        </Link>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                    <p className="type-small text-muted-foreground">
                        launchroom can’t generate{' '}
                        {required.length === 1 ? 'this value' : 'these values'}.
                        A deploy without {required.length === 1 ? 'it' : 'them'}{' '}
                        stops before the app starts.
                    </p>
                </div>
            )}

            {plan.sqliteWarning && (
                <p className="flex gap-2.5 rounded-xl border bg-status-progress-soft/50 px-4 py-3 type-small">
                    <TriangleAlert
                        aria-hidden="true"
                        className="mt-0.5 size-3.5 shrink-0 text-status-progress"
                    />
                    <span>
                        <span className="sr-only">Warning: </span>
                        {plan.sqliteWarning}
                    </span>
                </p>
            )}

            {volumes.length > 0 && (
                <div className="flex flex-col gap-2">
                    <span className="type-label text-muted-foreground">
                        Keep files across deploys
                    </span>
                    <ul
                        className="flex flex-col gap-1.5"
                        aria-label="Suggested volumes"
                    >
                        {volumes.map((suggestion) => (
                            <li
                                key={suggestion.path}
                                className="flex flex-wrap items-center gap-x-2 gap-y-1 type-small"
                            >
                                {route && canUpdate ? (
                                    <button
                                        type="button"
                                        disabled={adding !== null}
                                        onClick={() =>
                                            addVolume(suggestion.path)
                                        }
                                        className="inline-flex h-7 items-center gap-1.5 rounded-lg border bg-card px-2.5 type-small hover:bg-hover focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-60"
                                    >
                                        <Plus
                                            aria-hidden="true"
                                            className="size-3.5"
                                        />
                                        Volume at{' '}
                                        <code className="type-mono-small">
                                            {suggestion.path}
                                        </code>
                                    </button>
                                ) : (
                                    <span className="inline-flex h-7 items-center gap-1.5 rounded-lg border px-2.5">
                                        <HardDrive
                                            aria-hidden="true"
                                            className="size-3.5 text-muted-foreground"
                                        />
                                        <code className="type-mono-small">
                                            {suggestion.path}
                                        </code>
                                    </span>
                                )}
                                <span className="text-muted-foreground">
                                    {suggestion.reason}
                                </span>
                            </li>
                        ))}
                    </ul>
                    {route && canUpdate && (
                        <p className="type-small text-muted-foreground">
                            A service with a volume runs as one replica on one
                            server. The volume is added with the next deploy.
                        </p>
                    )}
                </div>
            )}

            {plan.envAliases.length > 0 && (
                <p className="type-small text-muted-foreground">
                    Set for each environment from its address:{' '}
                    <span className="font-mono text-foreground">
                        {plan.envAliases.join(', ')}
                    </span>
                </p>
            )}
        </div>
    );
}
