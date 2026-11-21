import { useState } from 'react';
import { CommitAvatar } from '@/components/commit-avatar';
import { Status, StatusIcon, StatusPill } from '@/components/status';
import {
    NotServingNote,
    RemedyButton,
} from '@/features/deployments/deployments-tab';
import { AttentionPopover } from '@/features/service-status/attention-popover';
import type {
    LiveStatus,
    ServiceStatus,
} from '@/features/service-status/live-status';
import { statusDetail } from '@/features/service-status/live-status';
import { seedLiveStatus } from '@/features/service-status/store';
import { vocabulary } from '@/lib/vocabulary';

/**
 * Service status (D108, docs/08 §23) with sample data, for review and
 * screenshots: every service word with its cause and age as nodes show it,
 * the panel pill, the current deployment card of a service that should run
 * but doesn't, and the top bar's attention popover with its fixes.
 */

const minutesAgo = (minutes: number) =>
    new Date(Date.now() - minutes * 60_000).toISOString();

function status(
    reason: App.Enums.NodeStatusReason,
    over: Partial<ServiceStatus> = {},
): ServiceStatus {
    const word = vocabulary.NodeStatusReason[reason];

    return {
        state: word.tone,
        reason,
        label: word.label,
        since: minutesAgo(60 * 26),
        deploymentId: 'dpl_gallery',
        detail: null,
        cause: null,
        remedy: null,
        ...over,
    };
}

const EXAMPLES: ServiceStatus[] = [
    status('live'),
    status('running'),
    status('starting', { since: minutesAgo(1) }),
    status('deploying', { label: 'Building', since: minutesAgo(1) }),
    status('not_running', {
        since: minutesAgo(120),
        cause: 'exited',
        remedy: 'start_containers',
    }),
    status('crashed', {
        since: minutesAgo(5),
        cause: 'ran out of memory',
        remedy: 'restart',
    }),
    status('unhealthy', {
        since: minutesAgo(12),
        cause: 'failing its health check',
    }),
    status('server_unreachable', {
        since: null,
        cause: 'fra-1 isn’t reporting',
    }),
    status('last_deploy_failed', {
        since: minutesAgo(30),
        remedy: 'retry_deploy',
    }),
    status('stopped', { since: minutesAgo(60 * 3), remedy: 'start' }),
];

const ENVIRONMENT = 'env_gallery_status';

function snapshot(): LiveStatus {
    const entry = (
        serviceId: string,
        name: string,
        logo: string,
        entryStatus: ServiceStatus,
        link: string,
        sentence: string,
    ): App.Data.Canvas.ServiceLiveStatusData => ({
        serviceId,
        name,
        logo,
        status: entryStatus,
        problem: {
            serviceId,
            serviceName: name,
            status: entryStatus,
            sentence,
            href: '#',
            link,
        },
    });

    return {
        environmentId: ENVIRONMENT,
        version: 1,
        health: {
            status: status('services_need_attention', {
                label: '4 services need attention',
                since: null,
                deploymentId: null,
            }),
            deploying: false,
            deployFailed: true,
        },
        services: [
            entry(
                'svc_web',
                'web',
                'nextjs',
                EXAMPLES[4],
                'View logs',
                'web is not running',
            ),
            entry(
                'svc_api',
                'api',
                'node',
                EXAMPLES[5],
                'View logs',
                'api crashed',
            ),
            entry(
                'svc_worker',
                'worker',
                'worker',
                EXAMPLES[7],
                'Open servers',
                'worker’s server isn’t reporting',
            ),
            entry(
                'svc_admin',
                'admin',
                'laravel',
                EXAMPLES[8],
                'See why',
                'The last deploy of admin failed',
            ),
        ],
    };
}

export default function StatusStates() {
    // The popover reads the live-status store like the top bar does.
    useState(() => {
        seedLiveStatus(snapshot());

        return null;
    });

    return (
        <div className="flex flex-col gap-8">
            <div className="grid gap-x-8 gap-y-3 sm:grid-cols-2">
                {EXAMPLES.map((example) => (
                    <div
                        key={`${example.reason}-${example.label}`}
                        className="flex min-w-0 items-center justify-between gap-3"
                    >
                        <Status
                            state={example.state}
                            label={example.label}
                            detail={statusDetail(example) ?? undefined}
                            className="min-w-0 type-small"
                        />
                        <StatusPill
                            state={example.state}
                            label={example.label}
                            className="hidden shrink-0 md:inline-flex"
                        />
                    </div>
                ))}
            </div>

            <div className="flex flex-col gap-2">
                <p className="type-label text-muted-foreground">
                    Current deployment of a service that should run but doesn’t
                </p>
                <div className="overflow-hidden rounded-xl border bg-card shadow-card">
                    <div className="grid grid-cols-[24px_minmax(0,1fr)] gap-x-3 gap-y-4 px-4 py-4">
                        <CommitAvatar name="bennett" size={24} />
                        <div className="flex min-w-0 flex-col gap-1">
                            <span className="truncate type-body font-medium">
                                fix: order totals with discounts
                            </span>
                            <span className="flex items-center gap-1.5 type-small text-muted-foreground">
                                <StatusIcon
                                    state={EXAMPLES[4].state}
                                    className="size-3"
                                />
                                <span>{EXAMPLES[4].label}</span>
                                <span className="type-mono-small">3b7d5e1</span>
                            </span>
                        </div>
                        <div className="col-start-2 min-w-0">
                            <NotServingNote
                                status={EXAMPLES[4]}
                                remedy={
                                    <RemedyButton
                                        remedy="start_containers"
                                        target={{
                                            project: 'prj_gallery',
                                            environment: ENVIRONMENT,
                                            service: 'svc_web',
                                            name: 'web',
                                            deployment: null,
                                        }}
                                    />
                                }
                            />
                        </div>
                    </div>
                </div>
            </div>

            <div className="flex flex-col gap-2 pb-96">
                <p className="type-label text-muted-foreground">
                    Top bar: what needs attention, and the fix
                </p>
                <div className="flex items-center gap-1.5 type-label">
                    shop / production
                    <AttentionPopover
                        project="prj_gallery"
                        environment={ENVIRONMENT}
                        status={snapshot().health.status}
                        canDeploy
                        defaultOpen
                    />
                </div>
            </div>
        </div>
    );
}
