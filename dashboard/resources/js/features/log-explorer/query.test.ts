import { expect, test } from 'vite-plus/test';
import {
    clearFilters,
    hasFilter,
    keepOnly,
    removeFilter,
    setFilter,
} from './query';

test('setFilter adds a filter and flips its polarity', () => {
    const only = setFilter('timeout', 'service', 'api', false);

    expect(only).toBe('service:api timeout');
    expect(setFilter(only, 'service', 'api', true)).toBe(
        '-service:api timeout',
    );
    expect(hasFilter('-server:"eu 1"', 'server', 'eu 1', true)).toBe(true);
});

test('keepOnly drops text and the other keys', () => {
    expect(
        keepOnly('service:api level:error deployment:abc1234 "boom"', [
            'service',
            'deployment',
        ]),
    ).toBe('service:api deployment:abc1234');
});

test('removeFilter and clearFilters keep the text', () => {
    expect(
        removeFilter('-service:api level:error boom', 'service', 'api'),
    ).toBe('level:error boom');
    expect(clearFilters('-service:api level:error boom')).toBe('boom');
});
