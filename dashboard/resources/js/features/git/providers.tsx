import { Logo } from '@/components/logo';
import type { ChoiceTile } from '@/components/patterns/choice-tiles';

export type ProviderChoice = 'github' | 'gitlab' | 'gitea' | 'git';

/** The four places code can live (docs/19 §4), as picker tiles. */
export const PROVIDER_CHOICES: ChoiceTile<ProviderChoice>[] = [
    {
        value: 'github',
        label: 'GitHub',
        description: 'With check runs and preview comments',
        media: <Logo kind="github" size={32} decorative />,
    },
    {
        value: 'gitlab',
        label: 'GitLab',
        description: 'gitlab.com or your own GitLab',
        media: <Logo kind="gitlab" size={32} decorative />,
    },
    {
        value: 'gitea',
        label: 'Gitea / Forgejo',
        description: 'Your own Gitea or Forgejo',
        media: <Logo kind="forgejo" size={32} decorative />,
    },
    {
        value: 'git',
        label: 'Any git server',
        description: 'SSH or HTTPS, with a deploy key',
        media: <Logo kind="git" size={32} decorative />,
    },
];

/** Logo key for a provider or server kind (`github`, `gitlab`, `gitea`, `forgejo`, `git`). */
export function providerLogo(kind: string): string {
    return kind === 'gitea' ||
        kind === 'forgejo' ||
        kind === 'gitlab' ||
        kind === 'github'
        ? kind
        : 'git';
}

/** Display name of a provider value (`GitSource::provider`, repository `provider`). */
export function providerLabel(provider: string): string {
    switch (provider) {
        case 'github':
            return 'GitHub';
        case 'gitlab':
            return 'GitLab';
        case 'gitea':
            return 'Gitea';
        default:
            return 'Git';
    }
}

/**
 * The mark of a picker repository: a plain git repository added by URL
 * (`github.com/owner/app`) shows its host's mark (docs/19 §4.3).
 */
export function repositoryLogo(serverKind: string, fullName: string): string {
    if (serverKind !== 'git') {
        return providerLogo(serverKind);
    }

    const host = fullName
        .split('/')[0]
        ?.toLowerCase()
        .replace(/^www\./, '');

    return host === 'github.com'
        ? 'github'
        : host === 'gitlab.com'
          ? 'gitlab'
          : host === 'codeberg.org'
            ? 'codeberg'
            : 'git';
}
