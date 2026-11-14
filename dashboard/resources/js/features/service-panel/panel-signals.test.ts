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
});

describe('secondarySignals', () => {
    it('leaves out alerts that repeat the status', () => {
        expect(
            secondarySignals(
                'Failed',
                [alert('deploy_failed', 'Deploy failed')],
                null,
            ),
        ).toEqual([]);
        expect(
            secondarySignals(
                'Unhealthy',
                [alert('service_unhealthy', 'Unhealthy')],
                null,
            ),
        ).toEqual([]);
    });

    it('keeps alerts that say something else', () => {
        const errors = alert('error_rate_high', 'Errors 8%', 'warning');

        expect(secondarySignals('Live', [errors], null)).toEqual([errors]);
        expect(
            secondarySignals(
                'Live',
                [alert('deploy_failed', 'Deploy failed')],
                null,
            ),
        ).toHaveLength(1);
    });

    it('puts Action needed first and drops vulnerability alerts it counts', () => {
        const signals = secondarySignals(
            'Live',
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
                label: 'Last deploy failed',
                since: null,
                deploymentId: 'dep_1',
            }),
        ).toBe('dep_1');
        expect(
            failedDeployment({
                state: 'active',
                label: 'Live',
                since: null,
                deploymentId: 'dep_1',
            }),
        ).toBeNull();
    });
});
