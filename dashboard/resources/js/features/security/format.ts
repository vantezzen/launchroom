import { colorForSlot, OTHER } from '@/components/charts/palette';

export type Severity = 'critical' | 'high' | 'medium' | 'low' | 'unknown';

export const SEVERITY_LABEL: Record<Severity, string> = {
    critical: 'Critical',
    high: 'High',
    medium: 'Medium',
    low: 'Low',
    unknown: 'No severity',
};

/** Firewall chart series in palette order (docs/22 §9.2). */
export const SERIES_COLOR: Record<string, string> = {
    sqli: colorForSlot(1),
    xss: colorForSlot(2),
    rce: colorForSlot(3),
    scanner: colorForSlot(4),
    other: OTHER,
    detected: OTHER,
};

export const CATEGORY_LABEL: Record<string, string> = {
    method: 'Disallowed methods',
    scanner: 'Scanners',
    protocol: 'Protocol abuse',
    lfi: 'File access',
    rfi: 'Remote file inclusion',
    rce: 'Code execution',
    php: 'PHP injection',
    generic: 'Other injections',
    xss: 'Cross-site scripting',
    sqli: 'SQL injection',
    session_fixation: 'Session fixation',
    java: 'Java attacks',
    other: 'Other',
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

export function plural(count: number, one: string, many = `${one}s`): string {
    return `${count.toLocaleString('en')} ${count === 1 ? one : many}`;
}

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
