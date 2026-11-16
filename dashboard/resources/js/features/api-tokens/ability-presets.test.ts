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
    test('an owner gets every preset and Everything is every ability', () => {
        const owner = team('owner');

        expect(offeredPresets(owner)).toEqual([
            'deploy',
            'read',
            'everything',
            'custom',
        ]);
        expect(presetAbilities(owner, 'everything')).toHaveLength(7);
        expect(presetOf(owner, ['deploy', 'env:read', 'env:write'])).toBe(
            'deploy',
        );
        expect(presetOf(owner, ['read', 'secrets:read'])).toBe('custom');
    });

    test('a viewer only gets Read only and Custom', () => {
        const viewer = team('viewer', [
            'deploy',
            'env:read',
            'env:write',
            'secrets:read',
            'domains:write',
            'data:transfer',
        ]);

        expect(offeredPresets(viewer)).toEqual(['read', 'custom']);
        expect(presetOf(viewer, ['read'])).toBe('read');
    });

    test('token rows summarise their abilities in one phrase', () => {
        const developer = team('developer', ['secrets:read', 'data:transfer']);

        expect(abilitySummary(['read']).label).toBe('Read only');
        expect(
            abilitySummary(['read', 'deploy', 'env:read', 'env:write']).label,
        ).toBe('Deploy');
        expect(
            abilitySummary(presetAbilities(developer, 'everything'), developer)
                .label,
        ).toBe('Full access');
        expect(abilitySummary(['read', 'secrets:read'])).toEqual({
            label: 'Custom, 2 permissions',
            custom: true,
        });
    });
});
