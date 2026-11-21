import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { AppUpdatesList } from '@/features/app-updates/app-updates-section';
import type {
    ServiceUpdate,
    UpdateCandidate,
    UpdateRun,
} from '@/features/app-updates/format';
import { UpdateDialog } from '@/features/app-updates/update-dialog';
import { UpdateStrip } from '@/features/app-updates/update-strip';
import { UpdatesCard } from '@/features/app-updates/updates-card';

/**
 * App updates (D106, docs/04 §14.4) with sample data: the service panel's
 * row in every state, Settings → Source → Updates, the update dialog and
 * the Projects page's list. Buttons post nowhere useful.
 */

const DAY = 24 * 3600 * 1000;
const ago = (days: number) => new Date(Date.now() - days * DAY).toISOString();
const inMinutes = (minutes: number) =>
    new Date(Date.now() + minutes * 60_000).toISOString();

function candidate(
    version: string,
    kind: UpdateCandidate['kind'],
    days: number,
    curatedBy: string | null = null,
): UpdateCandidate {
    return {
        kind,
        tag: version,
        version,
        digest: `sha256:${'b'.repeat(64)}`,
        publishedAt: ago(days),
        firstSeenAt: ago(days),
        curatedBy,
    };
}

const minor = candidate('v6.3.0', 'minor', 12, 'Listmonk');
const patch = candidate('v6.2.4', 'patch', 30);
const major = candidate('v7.0.1', 'major', 5);

const base: ServiceUpdate = {
    serviceId: 'svc_listmonk',
    serviceName: 'listmonk',
    logo: 'listmonk',
    projectId: 'prj_news',
    projectName: 'newsletter',
    environmentId: 'env_prod',
    environmentName: 'production',
    production: true,
    status: 'available',
    reason: null,
    running: 'v6.2.0',
    moving: false,
    standsOut: false,
    runningPublishedAt: ago(95),
    image: 'docker.io/listmonk/listmonk:v6.2.0',
    candidates: [minor, patch],
    recommended: minor,
    major: null,
    releaseNotesUrl: 'https://github.com/knadh/listmonk/releases',
    checkedAt: ago(0.1),
    run: null,
    checkable: true,
    canUpdate: true,
    blocker: null,
    backups: ['postgres', 'listmonk’s volume'],
    keepsData: true,
    backupStorage: true,
    watchMinutes: 10,
    autoUpdate: 'off',
    window: null,
    autoBlocker: null,
};

function run(
    status: UpdateRun['status'],
    extra: Partial<UpdateRun> = {},
): UpdateRun {
    return {
        id: 'apu_1',
        status,
        automatic: false,
        kind: 'minor',
        fromVersion: 'v6.2.0',
        toVersion: 'v6.3.0',
        actor: 'Mara',
        deploymentId: 'dpl_1',
        deploymentHref: '#',
        message: null,
        backupNote: null,
        backingUp: [],
        startedAt: ago(0.01),
        finishedAt: null,
        watchUntil: null,
        ...extra,
    };
}

const old: ServiceUpdate = {
    ...base,
    running: 'v4.1.0',
    runningPublishedAt: ago(3 * 365 + 40),
    candidates: [major, minor],
    recommended: minor,
    major,
    standsOut: true,
};

const strips: { label: string; update: ServiceUpdate }[] = [
    { label: 'Minor available', update: base },
    { label: 'Three years behind, a major out too', update: old },
    {
        label: 'Only a major',
        update: {
            ...base,
            candidates: [major],
            recommended: major,
            major,
            standsOut: true,
        },
    },
    {
        label: 'Moving tag',
        update: {
            ...base,
            serviceName: 'nginx',
            logo: 'nginx',
            running: 'latest',
            moving: true,
            runningPublishedAt: ago(240),
            candidates: [{ ...candidate('1.31.9', 'build', 3), tag: 'latest' }],
            recommended: { ...candidate('1.31.9', 'build', 3), tag: 'latest' },
            releaseNotesUrl: null,
        },
    },
    {
        label: 'Backing up',
        update: {
            ...base,
            run: run('backing_up', {
                backingUp: ['postgres', 'listmonk’s volume'],
            }),
        },
    },
    {
        label: 'Watching',
        update: { ...base, run: run('watching', { watchUntil: inMinutes(8) }) },
    },
    {
        label: 'Rolled back',
        update: {
            ...base,
            run: run('rolled_back', {
                message:
                    'dpl_9 kept crashing (exited with code 1, restarted 4 times) after it went live, so launchroom rolled back to dpl_8.',
                finishedAt: ago(0.02),
            }),
        },
    },
    {
        label: 'Updated',
        update: {
            ...base,
            candidates: [],
            recommended: null,
            run: run('succeeded', { finishedAt: ago(0.01) }),
        },
    },
];

export function AppUpdateStatesGallery() {
    const [dialog, setDialog] = useState<ServiceUpdate | null>(null);

    return (
        <div className="flex flex-col gap-10">
            <div className="flex flex-col gap-3">
                <p className="type-label text-muted-foreground">
                    Service panel
                </p>
                {strips.map(({ label, update }) => (
                    <div key={label} className="flex flex-col gap-1.5">
                        <span className="type-small text-muted-foreground">
                            {label}
                        </span>
                        <UpdateStrip service={update} />
                    </div>
                ))}
            </div>

            <div className="flex flex-wrap gap-2">
                <Button variant="outline" onClick={() => setDialog(base)}>
                    Open the update dialog
                </Button>
                <Button
                    variant="outline"
                    onClick={() =>
                        setDialog({
                            ...old,
                            candidates: [major],
                            recommended: major,
                        })
                    }
                >
                    Open a major update
                </Button>
                <Button
                    variant="outline"
                    onClick={() =>
                        setDialog({
                            ...base,
                            backups: [],
                            backupStorage: false,
                            blocker:
                                'Deploy or discard the 2 other staged changes first, so the update goes live alone.',
                        })
                    }
                >
                    Open a blocked update
                </Button>
            </div>
            {dialog && (
                <UpdateDialog
                    service={dialog}
                    open
                    onOpenChange={(open) => !open && setDialog(null)}
                />
            )}

            <UpdatesCard
                service={{
                    ...base,
                    autoUpdate: 'patch',
                    window: {
                        days: [1, 2, 3, 4, 5],
                        start: '03:00',
                        hours: 3,
                        timezone: 'Europe/Berlin',
                    },
                }}
            />
            <UpdatesCard
                service={{
                    ...base,
                    status: 'current',
                    candidates: [],
                    recommended: null,
                    autoBlocker:
                        'Set up backup storage first: automatic updates back up the data before they run.',
                }}
            />

            <AppUpdatesList
                updates={[
                    old,
                    strips[3].update,
                    {
                        ...base,
                        serviceName: 'umami',
                        logo: 'umami',
                        projectName: 'analytics',
                        running: '3.4.0',
                        candidates: [candidate('3.4.2', 'patch', 9)],
                        recommended: candidate('3.4.2', 'patch', 9),
                    },
                    {
                        ...base,
                        serviceName: 'ghost',
                        logo: 'ghost',
                        projectName: 'blog',
                        environmentName: 'staging',
                        production: false,
                        run: run('deploying', {
                            fromVersion: '6.71.0',
                            toVersion: '6.72.0',
                        }),
                    },
                ]}
            />
        </div>
    );
}
