import { describe, expect, test } from 'vite-plus/test';
import {
    compactAge,
    compactUntil,
    dayLabel,
    formatBandwidth,
    formatBytes,
    formatCompact,
    formatCores,
    formatDuration,
    formatEstimate,
    formatLatency,
    formatMemory,
    formatMs,
    formatRate,
    formatShare,
    initials,
    listNames,
    plural,
    shortAge,
    shortSha,
} from './format';

const now = Date.parse('2026-11-14T12:00:00Z');
const ago = (seconds: number) => new Date(now - seconds * 1000).toISOString();

describe('format', () => {
    test('bytes use binary units with one decimal under 10', () => {
        expect(formatBytes(512)).toBe('512 B');
        expect(formatBytes(1536)).toBe('1.5 KB');
        expect(formatBytes(340 * 1024 ** 2)).toBe('340 MB');
        expect(formatBytes(4.1 * 1024 ** 3)).toBe('4.1 GB');
        expect(formatBytes(null)).toBe('—');
        expect(formatBytes(null, 'size unknown')).toBe('size unknown');
    });

    test('memory and disk in megabytes use the same units as bytes', () => {
        expect(formatMemory(512)).toBe('512 MB');
        expect(formatMemory(1536)).toBe('1.5 GB');
        expect(formatMemory(12 * 1024)).toBe('12 GB');
        expect(formatMemory(3 * 1024 * 1024)).toBe('3.0 TB');
        expect(formatMemory(0)).toBe('0 MB');
        expect(formatMemory(null)).toBe('—');
        expect(formatMemory(undefined, '')).toBe('');
    });

    test('cores pluralise on the shown value', () => {
        expect(formatCores(1)).toBe('1 core');
        expect(formatCores(1.001)).toBe('1 core');
        expect(formatCores(0.25)).toBe('0.25 cores');
        expect(formatCores(2.5)).toBe('2.5 cores');
    });

    test('latency, rates and bandwidth', () => {
        expect(formatLatency(0.4)).toBe('0.4 ms');
        expect(formatLatency(38.2)).toBe('38 ms');
        expect(formatLatency(1200)).toBe('1.2 s');
        expect(formatLatency(45_000)).toBe('> 30 s');
        expect(formatRate(0)).toBe('0');
        expect(formatRate(0.005)).toBe('<0.01');
        expect(formatRate(4.44)).toBe('4.4');
        expect(formatRate(12.44)).toBe('12');
        expect(formatRate(1200)).toBe('1.2K');
        expect(formatCompact(34_000_000)).toBe('34M');
        expect(formatBandwidth(1536)).toBe('1.5 KB/s');
    });

    test('durations are compact', () => {
        expect(formatDuration(42)).toBe('42s');
        expect(formatDuration(102)).toBe('1m 42s');
        expect(formatDuration(3780)).toBe('1h 3m');
        expect(formatDuration(null)).toBeNull();
        expect(formatMs(73_400)).toBe('1m 13s');
        expect(formatMs(null)).toBeNull();
        expect(formatEstimate(30)).toBe('under a minute');
        expect(formatEstimate(150)).toBe('about 3 min');
        expect(formatEstimate(3900)).toBe('about 1 h 5 min');
    });

    test('ages', () => {
        expect(compactAge(ago(10), now)).toBe('now');
        expect(compactAge(ago(50), now)).toBe('1m');
        expect(compactAge(ago(3 * 3600), now)).toBe('3h');
        expect(compactAge(ago(5 * 86_400), now)).toBe('5d');
        expect(shortAge(ago(2), now)).toBe('now');
        expect(shortAge(ago(12), now)).toBe('12s ago');
        expect(shortAge(ago(480), now)).toBe('8m ago');
        expect(shortAge(null, now)).toBeNull();
        expect(compactUntil(new Date(now + 30_000).toISOString(), now)).toBe(
            '1m',
        );
    });

    test('day labels', () => {
        const today = new Date(now);
        expect(dayLabel(new Date(now).toISOString(), today)).toBe('Today');
        expect(dayLabel(ago(86_400), today)).toBe('Yesterday');
    });

    test('words', () => {
        expect(plural(1, 'server')).toBe('1 server');
        expect(plural(1204, 'request')).toBe('1,204 requests');
        expect(plural(2, 'process', 'processes')).toBe('2 processes');
        expect(listNames(['web'])).toBe('web');
        expect(listNames(['web', 'api', 'worker'])).toBe('web, api and worker');
        expect(initials('Ada Lovelace')).toBe('AL');
        expect(initials('vantezzen')).toBe('VA');
        expect(initials('john.doe')).toBe('JD');
        expect(initials('')).toBe('?');
        expect(shortSha('3b7d5e1c0ffee')).toBe('3b7d5e1');
        expect(formatShare(0.004)).toBe('<0.01%');
        expect(formatShare(4.25)).toBe('4.3%');
    });
});
