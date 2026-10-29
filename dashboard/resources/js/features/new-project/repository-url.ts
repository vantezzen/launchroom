/**
 * Client-side mirror of `App\Support\Git\PublicRepositoryUrl` (docs/19
 * §4.3) for live feedback while typing: provider mark, "looks complete"
 * and the error sentence. The server checks again; this never decides.
 */
export type RepositoryProvider = 'github' | 'gitlab' | 'codeberg' | 'git';

export type ParsedRepositoryUrl =
    | {
          ok: true;
          host: string;
          path: string;
          provider: RepositoryProvider;
          fullName: string;
      }
    | { ok: false; error: string; provider: RepositoryProvider | null };

const TWO_SEGMENT: Record<string, RepositoryProvider> = {
    'github.com': 'github',
    'codeberg.org': 'codeberg',
    'bitbucket.org': 'git',
};

const HOST =
    /^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}(?::[0-9]{1,5})?$/;
const SEGMENT = /^[A-Za-z0-9._~-]{1,100}$/;
const EXAMPLE = 'for example github.com/owner/app';

/** The provider a host belongs to, for the mark in the field. */
export function providerOfHost(host: string): RepositoryProvider {
    const bare = host.toLowerCase().replace(/^www\./, '');

    return TWO_SEGMENT[bare] ?? (bare === 'gitlab.com' ? 'gitlab' : 'git');
}

export function parseRepositoryUrl(input: string): ParsedRepositoryUrl {
    let url = input.trim();

    if (url === '') {
        return { ok: false, error: '', provider: null };
    }

    if (/^(ssh:\/\/|git@|git:\/\/)/i.test(url)) {
        return {
            ok: false,
            error: 'Paste the https:// address. SSH addresses need a deploy key: add them in Settings → Git.',
            provider: null,
        };
    }

    if (/^http:\/\//i.test(url)) {
        return {
            ok: false,
            error: 'Use the https:// address of the repository.',
            provider: null,
        };
    }

    url = url.replace(/^https:\/\//i, '');

    if (/[\s?#@\\]/.test(url)) {
        return {
            ok: false,
            error: 'Paste the repository URL without a query, fragment or credentials.',
            provider: null,
        };
    }

    let parts = url.replace(/\/+$/, '').split('/');
    const host = (parts.shift() ?? '').toLowerCase().replace(/^www\./, '');
    const provider = providerOfHost(host);

    if (!HOST.test(host)) {
        return {
            ok: false,
            error: `That doesn’t look like a repository URL, ${EXAMPLE}.`,
            provider: null,
        };
    }

    if (
        parts.some(
            (part) => !SEGMENT.test(part) || part === '.' || part === '..',
        )
    ) {
        return {
            ok: false,
            error: `That doesn’t look like a repository URL, ${EXAMPLE}.`,
            provider,
        };
    }

    const twoSegments = host in TWO_SEGMENT;

    if (twoSegments) {
        parts = parts.slice(0, 2);
    } else if (parts.includes('-')) {
        parts = parts.slice(0, parts.indexOf('-'));
    }

    if (parts.length > 0) {
        parts[parts.length - 1] = parts[parts.length - 1].replace(/\.git$/, '');
    }

    parts = parts.filter((part) => part !== '');

    if (
        parts.length < (twoSegments || host === 'gitlab.com' ? 2 : 1) ||
        parts.length > 10
    ) {
        return {
            ok: false,
            error: `Add the repository to the URL, ${EXAMPLE}.`,
            provider,
        };
    }

    const path = parts.join('/');

    return { ok: true, host, path, provider, fullName: `${host}/${path}` };
}
