import { describe, expect, test } from 'vite-plus/test';
import {
    abilitySummary,
    offeredPresets,
    presetAbilities,
    presetOf,
} from './ability-presets';

type Ability = App.Enums.ApiAbility;

function team(
    role: App.Enums.TeamRole,
    unavailable: Ability[] = [],
): App.Data.Api.ApiTeamOptionData {
    const all: Ability[] = [
        'read',
        'deploy',
        'env:read',
        'env:write',
        'secrets:read',
        'domains:write',
        'data:transfer',
    ];
    const defaults: Ability[] = ['read', 'deploy', 'env:read', 'env:write'];

    return {
        id: 'team_1',
        name: 'Acme',
        role,
        current: true,
        abilities: all.map((value) => ({
            value,
            label: value,
            description: '',
            available: !unavailable.includes(value),
            default: defaults.includes(value),
            locked: value === 'read',
        })),
    };
}

describe('access presets', () => {
    test('an owner gets every preset and Full access is every ability today', () => {
        const owner = team('owner');

        expect(offeredPresets(owner)).toEqual([
            'deploy',
            'read',
            'full',
            'custom',
        ]);
        expect(presetAbilities(owner, 'full')).toHaveLength(7);
        expect(presetOf(owner, ['deploy', 'env:read', 'env:write'])).toBe(
            'deploy',
        );
        expect(presetOf(owner, ['read', 'secrets:read'])).toBe('custom');
    });

    test('Full access is the flag, not a list that happens to be complete', () => {
        const owner = team('owner');
        const everything = presetAbilities(owner, 'full');

        expect(presetOf(owner, everything, true)).toBe('full');
        expect(presetOf(owner, ['read'], true)).toBe('full');
        expect(presetOf(owner, everything, false)).toBe('custom');
    });

    test('a viewer gets Read only, Full access and Custom', () => {
        const viewer = team('viewer', [
            'deploy',
            'env:read',
            'env:write',
            'secrets:read',
            'domains:write',
            'data:transfer',
        ]);

        expect(offeredPresets(viewer)).toEqual(['read', 'full', 'custom']);
        expect(presetOf(viewer, ['read'])).toBe('read');
    });

    test('token rows summarise their access in one phrase', () => {
        const custom = (abilities: Ability[]) =>
            abilitySummary({ abilities, fullAccess: false });

        expect(custom(['read']).label).toBe('Read only');
        expect(custom(['read', 'deploy', 'env:read', 'env:write']).label).toBe(
            'Deploy',
        );
        expect(
            abilitySummary({ abilities: ['read', 'deploy'], fullAccess: true }),
        ).toEqual({ label: 'Full access', custom: false });
        expect(custom(['read', 'secrets:read'])).toEqual({
            label: 'Custom, 2 permissions',
            custom: true,
        });
        // Every ability today, without the flag: it won't get later ones.
        expect(
            custom([
                'read',
                'deploy',
                'env:read',
                'env:write',
                'secrets:read',
                'domains:write',
                'data:transfer',
                'run',
                'fix',
                'update',
            ]).label,
        ).toBe('Custom, 10 permissions');
    });
});
