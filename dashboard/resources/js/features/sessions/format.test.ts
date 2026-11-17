import { expect, test } from 'vite-plus/test';
import { rowCount } from './format';

test('exact counts are shown as they are', () => {
    expect(rowCount(6, false)).toBe('6');
    expect(rowCount(0, undefined)).toBe('0');
    expect(rowCount(1234, false)).toBe('1,234');
    expect(rowCount(48000, false)).toBe('48K');
    expect(rowCount(48000, false, true)).toBe('48,000');
});

test('estimates are marked', () => {
    expect(rowCount(1200000, true)).toBe('~1.2M');
    expect(rowCount(1200000, true, true)).toBe('about 1.2M');
    expect(rowCount(40, true, true)).toBe('about 40');
});

test('no count shows nothing', () => {
    expect(rowCount(undefined, true)).toBeNull();
});
