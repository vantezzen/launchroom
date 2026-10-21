import { FileCode2 } from 'lucide-react';
import { shortSha } from '@/features/deployments/format';

type Staged = App.Data.ConfigAsCode.StagedConfigFileData;

/**
 * launchroom.json on the staged changes review (docs/18 §3.4–3.5): its
 * changes waiting here, settings the file will set again, or a file with
 * problems. A quiet callout, never a blocker.
 */
export function StagedConfigNote({ config }: { config: Staged | null }) {
    if (!config) {
        return null;
    }

    const sha = config.commitSha ? shortSha(config.commitSha) : null;
    const lines: string[] = [];

    if (config.status === 'invalid') {
        lines.push(
            `The latest ${config.path}${sha ? ` (${sha})` : ''} has problems, so nothing from it is here. Settings → General shows where.`,
        );
    } else if (config.waiting) {
        lines.push(
            `${config.path} changes from ${sha ?? 'the last push'} are part of these: another change was already staged, so the push didn’t deploy them. Deploy applies them together.`,
        );
    }

    if (config.managedPaths.length > 0 && !config.waiting) {
        lines.push(
            `Items marked ${config.path} are set by the file: it sets them again on the next push. Change them in the file to keep them.`,
        );
    }

    if (lines.length === 0) {
        return null;
    }

    return (
        <div
            role="note"
            className="flex gap-3 rounded-xl border bg-recessed px-4 py-3"
        >
            <FileCode2
                aria-hidden="true"
                className="mt-0.5 size-4 shrink-0 text-muted-foreground"
            />
            <div className="flex flex-col gap-1 type-small text-muted-foreground">
                {lines.map((line) => (
                    <p key={line}>{line}</p>
                ))}
            </div>
        </div>
    );
}
