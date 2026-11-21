import { describe, expect, it } from 'vitest';
import { applyFix, brokenSummary, fixFor } from './reference-fix';

function row(
    key: string,
    errors: string[] = [],
    fixes: App.Data.Variables.ReferenceFixData[] = [],
): App.Data.Variables.VariableRowData {
    return {
        key,
        errors,
        fixes,
    } as unknown as App.Data.Variables.VariableRowData;
}

describe('reference fixes', () => {
    it('finds the suggestions for a message by its expression', () => {
        const fix = {
            expression: '${{analytics.PUBLIC_URL}}',
            suggestions: ['${{web.PUBLIC_URL}}'],
        };
        const broken = row(
            'ANALYTICS_URL',
            [
                "${{analytics.PUBLIC_URL}} refers to a service that doesn't exist in this environment",
            ],
            [fix],
        );

        expect(fixFor(broken, broken.errors[0])).toBe(fix);
        expect(fixFor(broken, '${{x}} is part of a reference cycle')).toBe(
            null,
        );
    });

    it('replaces every use of the broken expression', () => {
        expect(
            applyFix('${{a.URL}}/x?b=${{a.URL}}', '${{a.URL}}', '${{web.URL}}'),
        ).toBe('${{web.URL}}/x?b=${{web.URL}}');
    });

    it('names the one broken variable, counts several', () => {
        expect(brokenSummary([row('A')])).toBe(null);
        expect(brokenSummary([row('A', ['x']), row('B')])).toBe(
            'A has a broken reference. Deploys are blocked until it’s fixed.',
        );
        expect(brokenSummary([row('A', ['x']), row('B', ['y'])])).toBe(
            '2 variables have broken references. Deploys are blocked until they’re fixed.',
        );
    });
});
