import type { ReactNode } from 'react';
import { ChecksCard } from '@/features/deployments/checks-card';

/**
 * Every state of "Deploy after checks pass" on the deployment page (D111,
 * docs/06 §2.5) with sample data, for review and screenshots. Deploy now
 * does nothing here.
 */

type Checks = App.Data.Deployments.DeploymentChecksData;

const SHA = '4f2a1c9e8b7d6a5f4e3d2c1b0a9f8e7d6c5b4a39';
const minutes = (offset: number): string =>
    new Date(Date.now() + offset * 60_000).toISOString();
const run = (id: number) =>
    `https://github.com/acme/shop/actions/runs/91827${id}/job/2231${id}`;

const running: App.Data.Git.CommitCheckData[] = [
    { name: 'test (node 22)', state: 'pending', url: run(1) },
    { name: 'lint', state: 'passed', url: run(2) },
    { name: 'typecheck', state: 'passed', url: run(3) },
    { name: 'e2e / chromium', state: 'pending', url: run(4) },
    { name: 'ci/circleci: build', state: 'passed', url: null },
];

function gate(
    state: App.Enums.CheckGateState,
    overrides: Partial<Checks> = {},
): Checks {
    return {
        state,
        since: minutes(-4),
        deadline: minutes(26),
        checks: running,
        skippedBy: null,
        problem: null,
        checkedAt: minutes(0),
        ...overrides,
    };
}

const noop = () => undefined;

export default function ChecksStates() {
    return (
        <div className="grid gap-6">
            <Frame label="Waiting: some checks still running">
                <ChecksCard
                    checks={gate('waiting')}
                    commitSha={SHA}
                    serving
                    canSkip
                    onSkip={noop}
                />
            </Frame>
            <Frame label="Waiting: no checks reported yet">
                <ChecksCard
                    checks={gate('waiting', { checks: [], since: minutes(0) })}
                    commitSha={SHA}
                    serving
                    canSkip
                    onSkip={noop}
                />
            </Frame>
            <Frame label="Waiting: GitHub doesn't answer">
                <ChecksCard
                    checks={gate('waiting', {
                        problem:
                            'GitHub didn’t answer with the checks of this commit.',
                    })}
                    commitSha={SHA}
                    serving
                    canSkip={false}
                />
            </Frame>
            <Frame label="Not deployed: a check failed">
                <ChecksCard
                    checks={gate('failed', {
                        checks: running.map((check) =>
                            check.name === 'test (node 22)'
                                ? { ...check, state: 'failed' }
                                : { ...check, state: 'passed' },
                        ),
                    })}
                    commitSha={SHA}
                    serving
                />
            </Frame>
            <Frame label="Not deployed: checks took too long">
                <ChecksCard
                    checks={gate('timed_out', {
                        since: minutes(-30),
                        deadline: minutes(0),
                    })}
                    commitSha={SHA}
                    serving
                />
            </Frame>
            <Frame label="Checks passed (rows folded)">
                <ChecksCard
                    checks={gate('passed', {
                        checks: running.map((check) => ({
                            ...check,
                            state: 'passed',
                        })),
                    })}
                    commitSha={SHA}
                />
            </Frame>
            <Frame label="No checks reported">
                <ChecksCard
                    checks={gate('none', { checks: [] })}
                    commitSha={SHA}
                />
            </Frame>
            <Frame label="Deploy now">
                <ChecksCard
                    checks={gate('skipped', { skippedBy: 'Ada Lovelace' })}
                    commitSha={SHA}
                />
            </Frame>
            <Frame label="A git server without checks">
                <ChecksCard
                    checks={gate('unsupported', { checks: [] })}
                    commitSha={SHA}
                />
            </Frame>
        </div>
    );
}

function Frame({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-2">
            <p className="type-small text-muted-foreground">{label}</p>
            <div className="max-w-[760px]">{children}</div>
        </div>
    );
}
