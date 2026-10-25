import { Bell, User } from 'lucide-react';
import { describe, expect, it } from 'vitest';
import { buildSettingsIndex, groupResults, searchSettings } from './search';
import type { SettingsNavGroup } from './types';

const groups: SettingsNavGroup<'profile' | 'alerts'>[] = [
    {
        id: 'account',
        label: 'Account',
        pages: [
            {
                id: 'profile',
                label: 'Profile',
                icon: User,
                summary: 'Name and email',
            },
        ],
    },
    {
        id: 'team',
        label: 'Team',
        pages: [
            {
                id: 'alerts',
                label: 'Notifications',
                icon: Bell,
                keywords: ['alerts'],
            },
        ],
    },
];

describe('buildSettingsIndex', () => {
    it('drops drafts that are hidden or point at a missing page', () => {
        const index = buildSettingsIndex(
            [
                {
                    target: 'profile',
                    label: 'Name',
                    hint: 'Shown on deploys',
                    keywords: [],
                },
                {
                    target: 'profile',
                    label: 'Email',
                    hint: 'Sign-in address',
                    keywords: [],
                    when: false,
                },
                {
                    target: 'variables' as 'profile',
                    label: 'Variables',
                    hint: 'Elsewhere',
                    keywords: [],
                },
            ],
            groups,
        );

        expect(index.map((entry) => entry.key)).toEqual(['profile:Name']);
        expect(index[0].group).toBe('Profile');
    });

    it('adds page entries first and extra targets by name', () => {
        const index = buildSettingsIndex(
            [
                {
                    target: 'variables' as 'profile',
                    label: 'Variables',
                    hint: 'Elsewhere',
                    keywords: [],
                },
            ],
            groups,
            { pages: true, extra: { variables: 'Variables tab' } },
        );

        expect(index.map((entry) => [entry.label, entry.group])).toEqual([
            ['Profile', 'Profile'],
            ['Notifications', 'Notifications'],
            ['Variables', 'Variables tab'],
        ]);
        expect(index[0].hint).toBe('Name and email');
        expect(index[1].hint).toBe('Team');
    });
});

describe('searchSettings', () => {
    const index = buildSettingsIndex(
        [
            {
                target: 'profile',
                label: 'Delete account',
                hint: 'Remove your user',
                keywords: ['remove'],
            },
        ],
        groups,
        { pages: true },
    );

    it('matches word prefixes in labels, pages and keywords', () => {
        expect(searchSettings(index, 'alert').map((r) => r.label)).toEqual([
            'Notifications',
        ]);
        expect(searchSettings(index, 'del').map((r) => r.label)).toEqual([
            'Delete account',
        ]);
        expect(searchSettings(index, 'prof').map((r) => r.label)).toEqual([
            'Profile',
            'Delete account',
        ]);
    });

    it('needs every word to match and ignores an empty query', () => {
        expect(searchSettings(index, 'delete nothing')).toEqual([]);
        expect(searchSettings(index, '   ')).toEqual([]);
    });

    it('groups results by page in the order of their best result', () => {
        const grouped = groupResults(searchSettings(index, 'remove'));

        expect(grouped.map((group) => group.group)).toEqual(['Profile']);
    });
});
