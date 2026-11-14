import { expect, test } from 'vitest';
import { environmentSwitchHref, projectSwitchHref } from './switch-targets';

const target = {
    id: 'prj_b',
    name: 'b',
    iconUrl: null,
    environmentId: 'env_b',
};

test('switching environments keeps logs and the analytics view', () => {
    expect(environmentSwitchHref('projects/logs', 'prj_a', 'env_2')).toBe(
        '/p/prj_a/env_2/logs',
    );
    expect(
        environmentSwitchHref(
            'projects/analytics',
            'prj_a',
            'env_2',
            '/p/prj_a/env_1/analytics?range=7d',
        ),
    ).toBe('/p/prj_a/env_2/analytics');
    expect(
        environmentSwitchHref(
            'projects/analytics',
            'prj_a',
            'env_2',
            '/p/prj_a/env_1/analytics/resources',
        ),
    ).toBe('/p/prj_a/env_2/analytics/resources');
    expect(environmentSwitchHref('projects/canvas', 'prj_a', 'env_2')).toBe(
        '/p/prj_a/env_2',
    );
    // A deployment belongs to its environment: the canvas.
    expect(
        environmentSwitchHref('projects/deployments/show', 'prj_a', 'env_2'),
    ).toBe('/p/prj_a/env_2');
});

test('switching projects keeps the page and links straight to the canvas', () => {
    expect(projectSwitchHref('projects/canvas', '/p/prj_a/env_1', target)).toBe(
        '/p/prj_b/env_b',
    );
    expect(
        projectSwitchHref('projects/logs', '/p/prj_a/env_1/logs', target),
    ).toBe('/p/prj_b/env_b/logs');
    expect(
        projectSwitchHref(
            'projects/settings',
            '/p/prj_a/settings/danger',
            target,
        ),
    ).toBe('/p/prj_b/settings/danger');
    expect(
        projectSwitchHref(
            'projects/deployments/index',
            '/p/prj_a/deployments',
            target,
        ),
    ).toBe('/p/prj_b/deployments');
    // Without a production environment: the project's redirect.
    expect(
        projectSwitchHref('projects/canvas', '/p/prj_a/env_1', {
            ...target,
            environmentId: null,
        }),
    ).toBe('/p/prj_b');
});
