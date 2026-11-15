import { describe, expect, it } from 'vitest';
import { failedDeployment, secondarySignals } from './panel-signals';

const alert = (
    rule: App.Enums.Alerts.AlertRule,
    label: string,
    severity: App.Enums.Alerts.AlertSeverity = 'critical',
): App.Data.Alerts.OpenAlertData => ({
    id: `alt_${rule}`,
    rule,
    severity,
    label,
    title: label,
    href: `/alerts/${rule}`,
    openedAt: '2026-11-01T00:00:00Z',
    kind: null,
    backupKind: null,
    value: null,
});

describe('secondarySignals', () => {
    it('leaves out alerts that repeat the status', () => {
        expect(
            secondarySignals(
                'deploy_failed',
                [alert('deploy_failed', 'Deploy failed')],
                null,
            ),
        ).toEqual([]);
        expect(
            secondarySignals(
                'unhealthy',
                [alert('service_unhealthy', 'Unhealthy')],
                null,
            ),
        ).toEqual([]);
    });

    it('keeps alerts that say something else', () => {
        const errors = alert('error_rate_high', 'Errors 8%', 'warning');

        expect(secondarySignals('live', [errors], null)).toEqual([errors]);
        expect(
            secondarySignals(
                'live',
                [alert('deploy_failed', 'Deploy failed')],
                null,
            ),
        ).toHaveLength(1);
    });

    it('puts Action needed first and drops vulnerability alerts it counts', () => {
        const signals = secondarySignals(
            'live',
            [
                alert('vulnerability', 'Vulnerability'),
                alert('cpu_high', 'CPU 95%', 'warning'),
            ],
            { count: 2, href: '/security' },
        );

        expect(signals.map((signal) => signal.label)).toEqual([
            'Action needed 2',
            'CPU 95%',
        ]);
    });
});

describe('failedDeployment', () => {
    it('links failed deploys only', () => {
        expect(
            failedDeployment({
                state: 'warning',
                reason: 'last_deploy_failed',
                label: 'Last deploy failed',
                since: null,
                deploymentId: 'dep_1',
                detail: null,
            }),
        ).toBe('dep_1');
        expect(
            failedDeployment({
                state: 'active',
                reason: 'live',
                label: 'Live',
                since: null,
                deploymentId: 'dep_1',
                detail: null,
            }),
        ).toBeNull();
    });
});
