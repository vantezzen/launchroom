import type { StatusState } from '@/components/status';
import { itemState } from '@/features/moves/format';
import type {
    DnsSwitchData,
    DnsSwitchValue,
    MoveItem,
} from '@/features/moves/types';

/**
 * The parts of a move's progress list, in the order they show: what needs
 * you, what moves now, what waits, what stays where it is, what moved.
 */
export function groupItems<T extends MoveItem>(
    items: T[],
): { failed: T[]; active: T[]; waiting: T[]; other: T[]; done: T[] } {
    const failed = items.filter((item) => item.status === 'failed');
    const active = items.filter(
        (item) => item.status !== 'failed' && itemState(item)[0] === 'progress',
    );
    const waiting = items.filter((item) => item.status === 'pending');
    const done = items.filter((item) => item.status === 'done');
    const other = items.filter(
        (item) =>
            !failed.includes(item) &&
            !active.includes(item) &&
            !waiting.includes(item) &&
            !done.includes(item),
    );

    return { failed, active, waiting, other, done };
}

/** What Stop does to a failed service (the confirmation's one sentence). */
export function stopConsequence(item: MoveItem): string {
    const processes = item.processes;
    const along =
        processes.length > 0
            ? `, with ${new Intl.ListFormat('en', { type: 'conjunction' }).format(processes)},`
            : '';

    return `${item.name}${along} stops and doesn’t move. Start it again later from its page.`;
}

/**
 * Services of one project and environment together, in the order the
 * first of each appears (within one progress group).
 */
export function groupByScope<T extends MoveItem>(
    items: T[],
): { scope: string; items: T[] }[] {
    const groups = new Map<string, T[]>();

    for (const item of items) {
        groups.set(item.scope, [...(groups.get(item.scope) ?? []), item]);
    }

    return [...groups].map(([scope, entries]) => ({ scope, items: entries }));
}

/** A message with the technical part split off (shown under "Details"). */
export type ExplainedMessage = { summary: string; detail: string | null };

/** What the backend appends after the cause: where the service runs now. */
const OUTCOME =
    /^([^]*?\S)\s+((?:It (?:still )?runs on|Nothing was changed|Starting it on|Deploy it from|Back on|Its data is back)\b[^]*)$/;

const TECHNICAL =
    /exit(?:ed)?\s(?:with\s)?(?:code|status)|\bcode\s\d+|exception|traceback|errno|stderr|stdout|sqlstate|[{}<>[\]`=|\\]|\/[\w.-]+\/|\w\(\)|\n|\d{3,}/i;

/** Error codes like ENOENT or ECONNREFUSED (case matters here). */
const ERRNO = /\bE[A-Z]{3,}\b/;

function looksRaw(text: string): boolean {
    return TECHNICAL.test(text) || ERRNO.test(text);
}

function technical(text: string): boolean {
    return text.length > 120 || looksRaw(text);
}

function sentence(text: string): string {
    const trimmed = text.trim();

    return /[.!?]$/.test(trimmed) ? trimmed : `${trimmed}.`;
}

/**
 * Splits a backend message into the human sentence and the raw cause:
 * "The build failed: next build exited with code 1. It still runs on
 * hel-1." reads "The build failed. It still runs on hel-1." with the
 * exit line as its detail. Human messages stay whole; a technical one
 * without a human lead gets `fallback` as its summary.
 */
export function explainMessage(
    text: string,
    fallback: string,
): ExplainedMessage {
    const message = text.trim();
    const outcome = OUTCOME.exec(message);
    const head = outcome ? outcome[1] : message;
    const tail = outcome ? ` ${outcome[2].trim()}` : '';
    const colon = head.indexOf(': ');
    const paren = head.search(/\s\(/);
    const cut =
        paren > 0 && (colon < 0 || paren < colon) && /\)\.?$/.test(head)
            ? {
                  lead: head.slice(0, paren),
                  raw: head.slice(paren + 2).replace(/\)\.?$/, ''),
              }
            : colon > 0 && colon <= 90
              ? { lead: head.slice(0, colon), raw: head.slice(colon + 2) }
              : null;

    if (cut !== null && technical(cut.raw) && !looksRaw(cut.lead)) {
        return {
            summary: `${sentence(cut.lead)}${tail}`,
            detail: cut.raw.trim(),
        };
    }

    if (looksRaw(message) && (message.length > 160 || message.includes('\n'))) {
        return { summary: fallback, detail: message };
    }

    return { summary: message, detail: null };
}

/** The status word of a DNS record during a switch: amber until switched, never red. */
export function dnsRecordState(
    check: App.Data.Domains.DnsCheckData | null | undefined,
    previous: string[] = [],
): [StatusState, string] {
    if (!check) {
        return ['idle', 'Not checked'];
    }

    switch (check.status) {
        case 'ok':
            return ['active', 'Switched'];
        case 'missing':
            return ['warning', 'No record yet'];
        case 'proxied':
            return ['warning', 'Behind a proxy'];
        case 'unknown':
            return ['warning', 'Can’t compare yet'];
        case 'error':
            return ['warning', 'Lookup failed'];
        default:
            return [
                'warning',
                check.addresses.some((address) => previous.includes(address))
                    ? 'Still the old address'
                    : 'Points elsewhere',
            ];
    }
}

type DnsCheck = App.Data.Domains.DnsCheckData;

/** One hostname to switch, whatever the page's data looks like. */
export type DnsSwitchRecord = {
    /** "Dashboard", "Apps (wildcard)", "Custom domain". */
    kind: string;
    host: string;
    /** The records to set (the new server's addresses). */
    values: DnsSwitchValue[];
    /** Custom domains: the service it serves. */
    usedBy?: string | null;
    /** The addresses it points at today, when known. */
    previous?: string[];
    /** The live check; null when not checked, undefined while it loads. */
    check: DnsCheck | null | undefined;
};

const kindLabels: Record<string, string> = {
    dashboard: 'Dashboard',
    apps: 'Apps',
    domain: 'Domain',
};

/** Move plan records (server moves, the hand-over preview) with their live checks. */
export function fromMoveRecords(
    records: App.Data.Moves.MoveDnsRecordData[],
    live?: App.Data.Moves.MoveDnsRecordData[],
): DnsSwitchRecord[] {
    return records.map((record) => ({
        kind: kindLabels[record.kind] ?? record.kind,
        host: record.host,
        values: toValues(record.addresses),
        previous: record.remove,
        check:
            live?.find((entry) => entry.host === record.host)?.check ??
            record.check ??
            (live === undefined ? undefined : null),
    }));
}

/** The checklist after a move or a restore: every row points at the edge addresses. */
export function fromCutover(
    checks: App.Data.Moves.CutoverChecksData,
): DnsSwitchRecord[] {
    return checks.dns.map((row) => ({
        kind: row.label,
        host: row.host,
        values: toValues(checks.expectedAddresses),
        check: row.check,
    }));
}

/** The DNS switch after a control-plane move (old dashboard and new checklist). */
export function fromDnsSwitch(data: DnsSwitchData): DnsSwitchRecord[] {
    return data.records.map((record) => ({
        kind: record.label,
        host: record.name,
        values: record.values,
        previous: record.remove,
        usedBy: record.usedBy,
        check: record.check,
    }));
}

function toValues(addresses: string[]): DnsSwitchValue[] {
    return addresses.map((value) => ({
        type: value.includes(':') ? 'AAAA' : 'A',
        value,
    }));
}
