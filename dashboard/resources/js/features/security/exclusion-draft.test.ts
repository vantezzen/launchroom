import { describe, expect, test } from 'vite-plus/test';
import {
    allowBody,
    allowedToast,
    allowLabel,
    uncoveredNote,
} from './exclusion-draft';

type Draft = App.Data.Security.WafExclusionDraftData;

const draft = (over: Partial<Draft> = {}): Draft => ({
    rules: [
        {
            rule: 911100,
            message: 'Method is not allowed by policy',
            target: null,
        },
        {
            rule: 920420,
            message: 'Request content type is not allowed by policy',
            target: null,
        },
    ],
    path: '/v1/orders',
    uncovered: [],
    settingsHref: '/p/shop/production/s/analytics/settings/firewall',
    allowUrl: '/p/shop/production/s/analytics/firewall/exclusions/allow',
    ...over,
});

describe('allow requests like this', () => {
    test('sends one exception per rule that scored, all for the path', () => {
        expect(allowBody(draft())).toEqual({
            exclusions: [
                { rule: 911100, path: '/v1/orders', target: null },
                { rule: 920420, path: '/v1/orders', target: null },
            ],
        });
        expect(
            allowBody(
                draft({
                    rules: [{ rule: 942100, message: 'SQL', target: 'ARGS:q' }],
                    path: null,
                }),
            ).exclusions,
        ).toEqual([{ rule: 942100, path: null, target: 'ARGS:q' }]);
    });

    test('counts the exceptions on the button', () => {
        expect(allowLabel(draft())).toBe('Add 2 exceptions');
        expect(allowLabel(draft({ rules: [draft().rules[0]] }))).toBe(
            'Add exception',
        );
    });

    test('claims requests are allowed only when every rule is covered', () => {
        expect(allowedToast(draft(), 2)).toBe(
            'Requests like this are allowed now',
        );
        expect(allowedToast(draft(), 0)).toBe('These exceptions already exist');
        expect(allowedToast(draft({ uncovered: [950100] }), 2)).toBe(
            '2 exceptions added. Requests like this can still be blocked.',
        );
        expect(uncoveredNote(draft())).toBeNull();
        expect(uncoveredNote(draft({ uncovered: [950100] }))).toBe(
            "Rule 950100 can't be excepted, so requests like this can still be blocked.",
        );
        expect(uncoveredNote(draft({ uncovered: [950100, 951110] }))).toBe(
            "Rules 950100 and 951110 can't be excepted, so requests like this can still be blocked.",
        );
    });
});
