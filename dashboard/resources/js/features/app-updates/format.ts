import { listNames, relativeTime } from '@/lib/format';
import { vocabulary } from '@/lib/vocabulary';

export type ServiceUpdate = App.Data.AppUpdates.ServiceUpdateData;
export type UpdateCandidate = App.Data.AppUpdates.UpdateCandidateData;
export type UpdateRun = App.Data.AppUpdates.AppUpdateRunData;

const YEAR_MS = 365 * 24 * 3600 * 1000;

const ageFormat = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });

/**
 * "3 years ago", "5 months ago", else relativeTime's days and hours: a
 * version's age reads best in the largest unit.
 */
export function ageWords(iso: string, now: number = Date.now()): string {
    const days = (now - new Date(iso).getTime()) / (24 * 3600 * 1000);

    if (days >= 365) {
        return ageFormat.format(-Math.floor(days / 365), 'year');
    }

    if (days >= 60) {
        return ageFormat.format(-Math.floor(days / 30), 'month');
    }

    return relativeTime(iso, now);
}

/** "published 3 years ago", or null when the registry didn't say. */
export function publishedWords(
    iso: string | null,
    now: number = Date.now(),
): string | null {
    return iso ? `published ${ageWords(iso, now)}` : null;
}

/** A version older than a year deserves a word of its own. */
export function isOld(iso: string | null, now: number = Date.now()): boolean {
    return iso !== null && now - new Date(iso).getTime() >= YEAR_MS;
}

/** The kind as a word: "Minor update" (from the PHP enum). */
export function kindLabel(kind: App.Enums.AppUpdates.UpdateKind): string {
    return vocabulary.AppUpdates.UpdateKind[kind].label;
}

/**
 * The headline for an available candidate: "v6.3.0 is available", or for a
 * build of the same tag "A newer build of latest is available".
 */
export function availableLine(candidate: UpdateCandidate): string {
    if (candidate.kind === 'build') {
        return candidate.version === candidate.tag
            ? `A newer build of ${candidate.tag} is available`
            : `${candidate.tag} is ${candidate.version} now`;
    }

    return `${candidate.version} is available`;
}

/** What the target is called in buttons: "Update to v6.3.0", "Update to the newer build". */
export function targetName(candidate: UpdateCandidate): string {
    return candidate.kind === 'build' && candidate.version === candidate.tag
        ? 'the newer build'
        : candidate.version;
}

/** "You run v6.2.0, published 3 years ago." */
export function runningLine(
    update: ServiceUpdate,
    now?: number,
): string | null {
    if (!update.running) {
        return null;
    }

    const published = publishedWords(update.runningPublishedAt, now);

    return `You run ${update.running}${published ? `, ${published}` : ''}`;
}

/** The step an open update is in, in words. */
export function runStep(run: UpdateRun, now: number = Date.now()): string {
    switch (run.status) {
        case 'backing_up':
            return run.backingUp.length > 0
                ? `Backing up ${listNames(run.backingUp)}`
                : 'Backing up';
        case 'deploying':
            return `Deploying ${run.toVersion}`;
        case 'watching':
            return run.watchUntil
                ? `Watching ${run.toVersion} until ${timeOfDay(run.watchUntil)}`
                : `Watching ${run.toVersion}`;
        case 'succeeded':
            return `Updated to ${run.toVersion} ${relativeTime(run.finishedAt ?? run.startedAt, now)}`;
        case 'failed':
            return `The update to ${run.toVersion} didn’t go live`;
        case 'rolled_back':
            return `${run.toVersion} was rolled back`;
        case 'cancelled':
            return `The update to ${run.toVersion} was cancelled`;
    }
}

export function isOpen(run: UpdateRun | null): boolean {
    return (
        run !== null &&
        (run.status === 'backing_up' ||
            run.status === 'deploying' ||
            run.status === 'watching')
    );
}

const timeFormat = new Intl.DateTimeFormat(undefined, {
    hour: '2-digit',
    minute: '2-digit',
});

function timeOfDay(iso: string): string {
    return timeFormat.format(new Date(iso));
}
