import { colorForSlot, OTHER } from '@/components/charts/palette';

/** Firewall chart series in palette order (docs/22 §9.2). */
export const SERIES_COLOR: Record<string, string> = {
    sqli: colorForSlot(1),
    xss: colorForSlot(2),
    rce: colorForSlot(3),
    scanner: colorForSlot(4),
    other: OTHER,
    detected: OTHER,
};

export const SCOPE_LABEL: Record<string, string> = {
    runtime: 'Runtime',
    dev: 'Development',
    client: 'Browser',
    unknown: 'Unknown',
};

export const ECOSYSTEM_LABEL: Record<string, string> = {
    npm: 'npm',
    pypi: 'PyPI',
    go: 'Go',
    maven: 'Maven',
    nuget: 'NuGet',
    cargo: 'crates.io',
    rubygems: 'RubyGems',
    packagist: 'Packagist',
    hex: 'Hex',
    pub: 'Pub',
    deb: 'Debian package',
    apk: 'Alpine package',
    rpm: 'RPM package',
    binary: 'Binary',
};

export function shortDate(iso: string): string {
    return new Date(iso).toLocaleDateString('en', {
        day: 'numeric',
        month: 'short',
    });
}

export function longDate(iso: string): string {
    return new Date(iso).toLocaleDateString('en', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
}

/** A level-1 card (design-system §5). */
export const card = 'rounded-xl border bg-card shadow-card';
