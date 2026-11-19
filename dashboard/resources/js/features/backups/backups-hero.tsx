import { router } from '@inertiajs/react';
import { useState } from 'react';
import { PageHeader } from '@/components/patterns/page';
import { StatusIcon } from '@/components/status';
import type { StatusState } from '@/components/status';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { listNames } from '@/lib/format';
import { ActionReason } from './action-reason';
import { batchLabel, batchOutcome, heroTitle } from './overview-model';
import type { BackupEverything, BackupsSummary } from './page-types';

const tones: Record<BackupsSummary['state'], StatusState | null> = {
    unconfigured: null,
    running: 'progress',
    attention: 'warning',
    waiting: 'idle',
    ok: 'active',
};

/**
 * "Back up everything now": while a batch runs the button is its progress
 * ("Backing up 3 of 8"), disabled; a blocked one keeps its reason under it.
 */
function EverythingButton({ everything }: { everything: BackupEverything }) {
    const [starting, setStarting] = useState(false);
    const { action, batch } = everything;

    if (batch !== null && batch.finishedAt === null) {
        return (
            <Button variant="outline" disabled className="disabled:opacity-100">
                <Spinner />
                {batchLabel(batch)}
            </Button>
        );
    }

    if (action.url !== null) {
        const url = action.url;

        return (
            <Button
                variant="outline"
                disabled={starting}
                onClick={() =>
                    router.post(
                        url,
                        {},
                        {
                            preserveScroll: true,
                            onStart: () => setStarting(true),
                            onFinish: () => setStarting(false),
                        },
                    )
                }
            >
                {starting && <Spinner />}
                Back up everything now
            </Button>
        );
    }

    // Without storage the page itself is the setup; nothing to explain here.
    if (action.disabled === null || action.disabled.code === 'no_storage') {
        return null;
    }

    return (
        <div className="flex flex-col items-start gap-1.5 sm:items-end">
            <Button variant="outline" disabled>
                Back up everything now
            </Button>
            <ActionReason reason={action.disabled} className="sm:text-right" />
        </div>
    );
}

/**
 * The page header with the state as its headline (docs/design/flows.md
 * §14): one sentence with an icon, the items that need you as links, and
 * the run-all action. A finished batch says how it went for an hour.
 */
export function BackupsHero({
    summary,
    everything,
}: {
    summary: BackupsSummary;
    everything: BackupEverything;
}) {
    const tone = tones[summary.state];
    const batch = everything.batch;
    const outcome = batch !== null ? batchOutcome(batch) : null;

    return (
        <div className="flex flex-col gap-4">
            <PageHeader
                title="Backups"
                actions={<EverythingButton everything={everything} />}
            >
                {tone !== null && (
                    <p
                        className="flex items-start gap-2 type-body"
                        aria-live="polite"
                    >
                        <StatusIcon state={tone} className="mt-[3px]" />
                        <span>
                            {heroTitle(summary)}
                            {summary.state === 'attention' &&
                                summary.attention.length > 1 && (
                                    <span className="text-muted-foreground">
                                        {' '}
                                        {summary.attention.map(
                                            (item, index) => (
                                                <span key={item.id}>
                                                    {index > 0 &&
                                                        (index ===
                                                        summary.attention
                                                            .length -
                                                            1
                                                            ? ' and '
                                                            : ', ')}
                                                    <a
                                                        href={`#backup-${item.id}`}
                                                        className="text-signal underline-offset-4 hover:underline"
                                                    >
                                                        {item.name}
                                                    </a>
                                                </span>
                                            ),
                                        )}
                                        .
                                    </span>
                                )}
                        </span>
                    </p>
                )}
            </PageHeader>

            {batch !== null &&
                (outcome !== null || batch.refused.length > 0) && (
                    <div className="flex flex-col gap-1 type-small text-muted-foreground">
                        {outcome !== null && (
                            <p className="flex items-center gap-2">
                                <StatusIcon
                                    state={
                                        batch.failed > 0 ? 'failed' : 'active'
                                    }
                                />
                                <span className="text-foreground">
                                    {outcome}
                                </span>
                            </p>
                        )}
                        {batch.refused.length > 0 && (
                            <p>
                                Not started:{' '}
                                {listNames(
                                    batch.refused.map(
                                        (item) =>
                                            `${item.name} (${item.message.replace(/\.$/, '')})`,
                                    ),
                                )}
                                .
                            </p>
                        )}
                    </div>
                )}
        </div>
    );
}
