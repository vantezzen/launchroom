import { shortSha } from '@/features/deployments/format';

type Ref = Pick<
    App.Data.Deployments.DeploymentRefData,
    'commitSha' | 'queuedAt' | 'finishedAt'
>;

/**
 * How the stage names another deployment of the same service: by its short
 * sha, unless it built the same commit as this one ("Replaced by c663a95"
 * on a deployment of c663a95 reads as if it replaced itself). Then it is
 * named as another deploy with its time instead, and without a commit at
 * all by the caller's fallback words.
 */
export type RefName =
    | { kind: 'sha'; sha: string }
    | { kind: 'same-commit'; at: string }
    | { kind: 'unnamed' };

export function refName(
    self: { commitSha: string | null },
    target: Ref,
): RefName {
    const sha = shortSha(target.commitSha);

    if (sha === null) {
        return { kind: 'unnamed' };
    }

    return target.commitSha === self.commitSha
        ? { kind: 'same-commit', at: target.finishedAt ?? target.queuedAt }
        : { kind: 'sha', sha };
}
