import { useState } from 'react';
import type { ReactNode } from 'react';
import { StepCard } from '@/components/steps/step-card';
import { TrafficEmptyState } from '@/features/analytics/traffic-empty-state';
import {
    DoneSummary,
    OnboardingHero,
    WhatsNext,
} from '@/features/onboarding/checklist-progress';
import { vocabulary } from '@/lib/vocabulary';

/**
 * Get started's progress and the empty Traffic view (D109) with sample
 * data, for review and screenshots. Buttons act on sample ids only.
 */

type Progress = App.Data.Instance.OnboardingProgressData;
type Step = App.Data.Instance.OnboardingStepData;
type Readiness = App.Data.Analytics.TrafficReadinessData;

function Frame({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-3">
            <p className="type-label text-muted-foreground">{label}</p>
            <div className="flex flex-col gap-6 rounded-xl border bg-background p-6">
                {children}
            </div>
        </div>
    );
}

const progress = (over: Partial<Progress>): Progress => ({
    done: 3,
    skipped: 2,
    total: 5,
    settled: true,
    complete: false,
    dismissed: false,
    ...over,
});

const steps = (skipped: App.Enums.Instance.OnboardingStep[]): Step[] =>
    (['server', 'github', 'domain', 'project', 'backups'] as const).map(
        (id) => ({
            id,
            status: skipped.includes(id) ? 'skipped' : 'done',
            skippable: id !== 'server' && id !== 'project',
        }),
    );

const firstApp: App.Data.Instance.FirstAppData = {
    state: 'live',
    projectId: 'prj_gallery',
    projectName: 'shop',
    environmentId: 'env_gallery',
    serviceId: 'svc_gallery',
    serviceName: 'web',
    logo: 'nextjs',
    hostname: 'web-production.shop.203-0-113-7.sslip.io',
    liveSince: null,
    deploymentId: null,
    startedAt: null,
    steps: [],
    suggestGit: false,
    seen: false,
};

export function OnboardingProgressStates() {
    const [open, setOpen] = useState<string | null>(null);
    const settled = steps(['github', 'domain']);

    return (
        <div className="grid gap-8">
            <Frame label="Steps left">
                <OnboardingHero
                    progress={progress({
                        done: 2,
                        skipped: 1,
                        settled: false,
                    })}
                    projectPending
                    serverName="fra-1"
                />
            </Frame>
            <Frame label="Only optional steps left (two skipped)">
                <OnboardingHero
                    progress={progress({})}
                    projectPending={false}
                />
                <ol className="flex flex-col gap-3">
                    <DoneSummary steps={settled} />
                    <StepCard
                        id="gallery-github"
                        status="skipped"
                        number={2}
                        title="Connect GitHub"
                        line="Connect it to deploy on every push."
                        open={open === 'github'}
                        onOpen={() => setOpen('github')}
                    >
                        <p className="type-body text-muted-foreground">
                            The GitHub setup steps.
                        </p>
                    </StepCard>
                    <StepCard
                        id="gallery-domain"
                        status="skipped"
                        number={3}
                        title="Your apps use a free address for now"
                        line="*.203-0-113-7.sslip.io"
                        open={false}
                        onOpen={() => setOpen('domain')}
                    >
                        {null}
                    </StepCard>
                </ol>
                <WhatsNext
                    next={{ customDomain: true, invite: true }}
                    firstApp={firstApp}
                />
            </Frame>
            <Frame label="Complete">
                <OnboardingHero
                    progress={progress({
                        done: 5,
                        skipped: 0,
                        complete: true,
                    })}
                    projectPending={false}
                />
                <ol className="flex flex-col gap-3">
                    <DoneSummary steps={steps([])} />
                </ol>
                <WhatsNext
                    next={{ customDomain: false, invite: false }}
                    firstApp={firstApp}
                />
            </Frame>
        </div>
    );
}

function blocker(
    name: string,
    reason: App.Enums.NodeStatusReason,
    over: Partial<App.Data.Canvas.NodeStatusData> = {},
    project = 'shop',
): App.Data.Analytics.TrafficBlockerData {
    const word = vocabulary.NodeStatusReason[reason];

    return {
        serviceId: `svc_gallery_${project}_${name}`,
        name,
        logo: name === 'api' ? 'node' : 'nextjs',
        projectId: 'prj_gallery',
        projectName: project,
        environmentId: 'env_gallery',
        environmentName: 'production',
        status: {
            state: word.tone,
            reason,
            label: word.label,
            since: new Date(Date.now() - 3 * 3600_000).toISOString(),
            deploymentId: 'dpl_gallery',
            detail: null,
            cause: null,
            remedy: null,
            ...over,
        },
    };
}

const readiness = (over: Partial<Readiness>): Readiness => ({
    reason: 'waiting',
    address: null,
    publicServices: [],
    notServing: [],
    moreNotServing: 0,
    ...over,
});

export function AnalyticsEmptyStates() {
    return (
        <div className="grid gap-8">
            <Frame label="Nothing serves, one fix (may deploy)">
                <TrafficEmptyState
                    readiness={readiness({
                        reason: 'not_serving',
                        publicServices: ['svc_gallery_shop_web'],
                        notServing: [
                            blocker('web', 'stopped', { remedy: 'start' }),
                        ],
                    })}
                    service={null}
                    where="production"
                    range="24h"
                    canFix
                />
            </Frame>
            <Frame label="Nothing serves, several causes">
                <TrafficEmptyState
                    readiness={readiness({
                        reason: 'not_serving',
                        notServing: [
                            blocker('api', 'crashed', {
                                cause: 'ran out of memory',
                                remedy: 'restart',
                            }),
                            blocker('web', 'deploy_failed', {
                                remedy: 'retry_deploy',
                            }),
                        ],
                    })}
                    service={null}
                    where="production"
                    range="24h"
                    canFix
                />
            </Frame>
            <Frame label="Team Analytics: links instead of fixes">
                <TrafficEmptyState
                    readiness={readiness({
                        reason: 'not_serving',
                        notServing: [
                            blocker('web', 'stopped', { remedy: 'start' }),
                            blocker(
                                'web',
                                'server_down',
                                { cause: 'fra-1 is offline' },
                                'blog',
                            ),
                        ],
                        moreNotServing: 2,
                    })}
                    service={null}
                    where="production"
                    range="7d"
                    canFix={false}
                    showProject
                />
            </Frame>
            <Frame label="No address">
                <TrafficEmptyState
                    readiness={readiness({ reason: 'no_address' })}
                    service={null}
                    where="production"
                    range="24h"
                    canFix
                />
            </Frame>
            <Frame label="Waiting for the first request">
                <TrafficEmptyState
                    readiness={readiness({
                        address: 'web-production.shop.203-0-113-7.sslip.io',
                    })}
                    service={null}
                    where="production"
                    range="24h"
                    canFix
                />
            </Frame>
        </div>
    );
}
