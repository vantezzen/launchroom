import { describe, expect, it } from 'vite-plus/test';
import { badge, navGroup, phoneNav, teamNavItems } from './nav-items';

type Signals = Parameters<typeof teamNavItems>[0];

const signals = (overrides: Partial<Signals> = {}): Signals => ({
    onboardingProgress: null,
    updateAvailable: false,
    teams: { canSearchLogs: true } as Signals['teams'],
    securityActionNeeded: 0,
    openAlertCount: 0,
    docsUrl: 'https://launchroom.vantezzen.io/docs',
    ...overrides,
});

const titles = (items: { title: string }[]) => items.map((item) => item.title);

describe('teamNavItems (D114)', () => {
    it('puts the daily pages in the main group and the rest at the bottom', () => {
        const items = teamNavItems(signals());

        expect(titles(navGroup(items, 'start'))).toEqual([]);
        expect(titles(navGroup(items, 'main'))).toEqual([
            'Projects',
            'Servers',
            'Logs',
            'Analytics',
            'Security',
        ]);
        expect(titles(navGroup(items, 'bottom'))).toEqual([
            'Activity',
            'Docs',
            'Settings',
        ]);
        // Backups is a Settings page now.
        expect(titles(items)).not.toContain('Backups');
    });

    it('shows Get started until onboarding is done, and Logs only to those who may search', () => {
        const items = teamNavItems(
            signals({
                teams: { canSearchLogs: false } as Signals['teams'],
                onboardingProgress: {
                    done: 2,
                    total: 5,
                    complete: false,
                    settled: false,
                    dismissed: false,
                } as Signals['onboardingProgress'],
            }),
        );

        expect(navGroup(items, 'start')).toMatchObject([
            { title: 'Get started', meta: '2/5' },
        ]);
        expect(titles(items)).not.toContain('Logs');
    });

    it('keeps the counts and the update dot', () => {
        const items = teamNavItems(
            signals({
                openAlertCount: 120,
                securityActionNeeded: 6,
                updateAvailable: true,
            }),
        );
        const byTitle = Object.fromEntries(
            items.map((item) => [item.title, item]),
        );

        expect(byTitle.Activity.meta).toBe('99+');
        expect(byTitle.Security.meta).toBe('6');
        expect(byTitle.Settings.dot).toBe('Update ready');
        expect(byTitle.Docs).toMatchObject({
            external: true,
            href: 'https://launchroom.vantezzen.io/docs',
        });
    });
});

describe('phoneNav', () => {
    it('puts three daily pages in the bar and everything else in More', () => {
        const { bar, more } = phoneNav(teamNavItems(signals()));

        expect(titles(bar)).toEqual(['Projects', 'Servers', 'Logs']);
        expect(titles(more)).toEqual([
            'Analytics',
            'Security',
            'Activity',
            'Docs',
            'Settings',
        ]);
    });

    it('fills the bar with Analytics when Logs is not there', () => {
        const { bar, more } = phoneNav(
            teamNavItems(
                signals({
                    teams: { canSearchLogs: false } as Signals['teams'],
                }),
            ),
        );

        expect(titles(bar)).toEqual(['Projects', 'Servers', 'Analytics']);
        expect(titles(more)).toEqual([
            'Security',
            'Activity',
            'Docs',
            'Settings',
        ]);
    });
});

describe('badge', () => {
    it('stays readable', () => {
        expect(badge(0)).toBeUndefined();
        expect(badge(7)).toBe('7');
        expect(badge(100)).toBe('99+');
    });
});
