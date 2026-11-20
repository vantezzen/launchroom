import type { ReactNode } from 'react';
import { ControlPlaneMoveSteps } from '@/features/moves/control-plane-move';
import type { ControlPlaneMoveProps } from '@/features/moves/control-plane-move';
import { MoveChecklist } from '@/features/moves/move-checklist';
import type { Checklist } from '@/features/moves/move-checklist';
import { MoveProgress } from '@/features/moves/move-progress';
import { MovedScreen } from '@/features/moves/moved-screen';
import type { DnsSwitchData, MoveItem } from '@/features/moves/types';

/**
 * Every step of moving launchroom and a server (docs/14 §13), with sample
 * data, for review and screenshots. Buttons post nowhere useful.
 */

type Server = App.Data.Moves.MoveServerData;
type Move = App.Data.Moves.ControlPlaneMoveData;
type Item = MoveItem;
type DnsCheck = App.Data.Domains.DnsCheckData;

const NOW = '2026-11-19T09:30:00Z';
const OLD_IP = '198.51.100.24';
const NEW_IP = '203.0.113.71';
const COMMAND =
    "curl -fsSL https://launchroom.vantezzen.io/install.sh | sudo sh -s -- --move 'lrmv1.aHR0cHM6Ly9kYXNoLmFjbWUuZGV2.cpm_01M5F17T22.Zm9vYmFy.YmF6' --version 0.1.6";
const CONTINUE = './launchroom move continue';

const source: Server = {
    id: 'srv_old',
    name: 'hel-1',
    status: 'online',
    roles: ['edge', 'runtime', 'build'],
    isControlPlane: true,
    platform: 'linux/amd64',
    addresses: [OLD_IP],
    diskFreeMb: 48_000,
    device: 'dedicated',
};

const target: Server = {
    id: 'srv_new',
    name: 'fsn-2',
    status: 'online',
    roles: ['edge', 'runtime', 'build'],
    isControlPlane: false,
    platform: 'linux/arm64',
    addresses: [NEW_IP],
    diskFreeMb: 160_000,
    device: 'cloud',
};

const baseMove: Move = {
    id: 'cpm_demo',
    status: 'waiting_for_server',
    code: null,
    receiverHostname: null,
    receiverArch: null,
    receiverAddress: null,
    receiverSeenAt: null,
    receiverWaiting: false,
    source,
    target: null,
    error: null,
    tokenExpiresAt: '2026-11-20T09:30:00Z',
    frozenAt: null,
    handedOverAt: null,
    newUrl: null,
    dumpSize: null,
};

const claimed: Move = {
    ...baseMove,
    status: 'claimed',
    receiverHostname: 'fsn-2',
    receiverArch: 'linux/arm64',
    receiverAddress: NEW_IP,
};

const joined: Move = { ...claimed, status: 'joined', target };

function check(
    hostname: string,
    status: App.Enums.Domains.DnsCheckStatus,
    addresses: string[],
    message: string,
): DnsCheck {
    return {
        status,
        hostname,
        addresses,
        expected: [NEW_IP],
        cname: null,
        message,
        checkedAt: NOW,
        proxy: null,
        reachedOverHttp: false,
    };
}

const records: App.Data.Moves.MoveDnsRecordData[] = [
    {
        kind: 'dashboard',
        host: 'dash.acme.dev',
        addresses: [NEW_IP],
        remove: [OLD_IP],
        check: check(
            'dash.acme.dev',
            'ok',
            [NEW_IP],
            'dash.acme.dev points at 203.0.113.71.',
        ),
    },
    {
        kind: 'apps',
        host: '*.apps.acme.dev',
        addresses: [NEW_IP],
        remove: [OLD_IP],
        check: check(
            'lr-check-1a2b.apps.acme.dev',
            'mismatch',
            [OLD_IP],
            'The wildcard record points at 198.51.100.24, not 203.0.113.71. DNS changes can take a few minutes to show up.',
        ),
    },
    {
        kind: 'domain',
        host: 'shop.acme.com',
        addresses: [NEW_IP],
        remove: [OLD_IP],
        check: check(
            'shop.acme.com',
            'missing',
            [],
            'No A or AAAA record for shop.acme.com yet.',
        ),
    },
];

const dnsSwitch: DnsSwitchData = {
    newUrl: 'https://dash.acme.dev',
    addresses: [{ type: 'A', value: NEW_IP }],
    oldAddresses: [OLD_IP],
    records: records.map((record) => ({
        kind: record.kind,
        label:
            record.kind === 'dashboard'
                ? 'Dashboard'
                : record.kind === 'apps'
                  ? 'Apps (wildcard)'
                  : 'Custom domain',
        name: record.host,
        values: [{ type: 'A', value: NEW_IP }],
        remove: [OLD_IP],
        usedBy: record.kind === 'domain' ? 'web (shop / production)' : null,
        check:
            record.kind === 'dashboard'
                ? check(
                      'dash.acme.dev',
                      'mismatch',
                      [OLD_IP],
                      'dash.acme.dev points at 198.51.100.24, not 203.0.113.71. DNS changes can take a few minutes to show up.',
                  )
                : record.check,
    })),
    reachBeforeDns: {
        command: `ssh -L 8000:127.0.0.1:8000 root@${NEW_IP}`,
        url: 'http://localhost:8000',
    },
    checked: true,
    pointed: 0,
    total: 3,
};

function item(
    id: string,
    name: string,
    kind: App.Enums.Moves.MoveItemKind,
    status: App.Enums.Moves.MoveItemStatus,
    extra: Partial<Item> = {},
): Item {
    return {
        id,
        instanceId: `${id}-i`,
        name,
        service: name.replace(/ \(.*\)$/, ''),
        scope: /\((.*)\)$/.exec(name)?.[1] ?? 'tools / production',
        kind,
        status,
        failedStep: null,
        message: null,
        expectedBytes: null,
        copiedBytes: null,
        attempts: 1,
        stoppedAt: null,
        startedAt: null,
        finishedAt: null,
        canRetry: false,
        canStop: false,
        processes: [],
        href: '#',
        ...extra,
    };
}

const items: Item[] = [
    item('i1', 'web (shop / production)', 'stateless', 'failed', {
        canRetry: true,
        canStop: true,
        processes: ['queue', 'nightly-report'],
        failedStep: 'building',
        message:
            'The build failed: next build exited with code 1 (Cannot find module ‘sharp’). It still runs on hel-1.',
    }),
    item('i2', 'orders-db (shop / production)', 'dump', 'exporting', {
        expectedBytes: 3_400_000_000,
        copiedBytes: 1_200_000_000,
        message: 'Stops web and api while it copies.',
    }),
    item('i3', 'Uptime Kuma (status / production)', 'files', 'pending', {
        expectedBytes: 82_000_000,
    }),
    item('i4', 'api (shop / production)', 'stateless', 'done'),
    item('i5', 'worker (shop / production)', 'stateless', 'done'),
    item('i6', 'site (blog / production)', 'stateless', 'done'),
    item('i7', 'docs (blog / production)', 'stateless', 'done'),
    item('i8', 'cache (shop / production)', 'files', 'done', {
        copiedBytes: 12_000_000,
    }),
    item('i9', 'search-db (blog / production)', 'dump', 'failed', {
        canRetry: true,
        failedStep: 'importing',
        expectedBytes: 640_000_000,
        attempts: 2,
        message:
            'Loading it on fsn-2 failed: pg_restore: error: could not execute query: ERROR:  role "search" does not exist\nCommand was: ALTER TABLE public.documents OWNER TO search; It runs on hel-1 again.',
    }),
];

const serverMove: App.Data.Moves.ServerMoveData = {
    id: 'smv_demo',
    status: 'running',
    source,
    target,
    controlPlane: true,
    edgeAdded: false,
    backupFirst: false,
    items,
    done: 5,
    failed: 2,
    error: null,
    createdAt: NOW,
    readyAt: null,
    finishedAt: null,
};

const plan: App.Data.Moves.MovePlanData = {
    source,
    target,
    items: [
        {
            instanceId: 'a',
            service: 'web',
            name: 'web (shop / production)',
            kind: 'stateless',
            engine: null,
            expectedBytes: null,
            downtimeSeconds: 0,
            rebuild: true,
            stops: [],
            note: null,
        },
        {
            instanceId: 'b',
            service: 'orders-db',
            name: 'orders-db (shop / production)',
            kind: 'dump',
            engine: 'PostgreSQL 16',
            expectedBytes: 3_400_000_000,
            downtimeSeconds: 385,
            rebuild: false,
            stops: ['web', 'api'],
            note: null,
        },
    ],
    blockers: [],
    warnings: [],
    dns: records,
    platformFrom: 'linux/amd64',
    platformTo: 'linux/arm64',
    addsEdge: false,
    downtimeSeconds: 385,
    copyBytes: 3_400_000_000,
    canBackUpFirst: true,
};

const preflight: App.Data.Moves.MovePreflightCheckData[] = [
    {
        key: 'version',
        label: 'Release',
        status: 'ok',
        message: 'Both machines run launchroom 0.1.6.',
        href: null,
        hrefLabel: null,
    },
    {
        key: 'builds',
        label: 'Builds',
        status: 'blocker',
        message:
            'No online server builds apps. Turn on Build for a server, or for the new one once it joined.',
        href: '/servers',
        hrefLabel: 'Open Servers',
    },
    {
        key: 'servers',
        label: 'Other servers',
        status: 'warning',
        message:
            'ams-3 reaches this dashboard by its IP address. Change LR_CONTROL_PLANE_URL there after the move.',
        href: null,
        hrefLabel: null,
    },
];

const stop = () => ({ action: '#', method: 'post' as const });

const base: ControlPlaneMoveProps = {
    move: null,
    server: source,
    command: null,
    preflight: preflight.filter((entry) => entry.status !== 'blocker'),
    plan: null,
    serverMove: null,
    continueCommand: CONTINUE,
    handoverBlockers: [],
    stopForm: stop,
};

const STATES: { id: string; title: string; props: ControlPlaneMoveProps }[] = [
    {
        id: 'prepare',
        title: 'Prepare, with a blocker',
        props: { ...base, preflight },
    },
    {
        id: 'cancelled',
        title: 'Prepare, after a move that stopped with an error',
        props: {
            ...base,
            move: {
                ...baseMove,
                status: 'cancelled',
                error: 'The new machine stopped: curl: (7) Failed to connect to 203.0.113.71 port 8443 after 3021 ms: Connection refused',
            },
        },
    },
    {
        id: 'waiting',
        title: 'Waiting for the new machine',
        props: { ...base, move: baseMove, command: COMMAND },
    },
    {
        id: 'claimed',
        title: 'Code confirmation',
        props: { ...base, move: claimed },
    },
    {
        id: 'joining',
        title: 'Joining stalled',
        props: {
            ...base,
            move: {
                ...claimed,
                status: 'joining',
                error: 'The new server hasn’t used its join token for 5 minutes. If its terminal says it joined, it was already connected to this dashboard: the continue command below picks the move up with that server. Otherwise check its terminal for errors, or cancel the move.',
            },
        },
    },
    {
        id: 'joined',
        title: 'Joined, plan',
        props: { ...base, move: joined, plan },
    },
    {
        id: 'moving',
        title: 'Moving, two failed',
        props: { ...base, move: { ...joined, status: 'moving' }, serverMove },
    },
    {
        id: 'handover-blocked',
        title: 'Hand-over, blocked',
        props: {
            ...base,
            move: { ...joined, status: 'ready_to_hand_over' },
            serverMove: {
                ...serverMove,
                status: 'ready',
                done: items.length,
                failed: 0,
                items: items.map((entry) => ({ ...entry, status: 'done' })),
            },
            dns: records,
            handoverBlockers: [
                {
                    service: 'worker',
                    project: 'shop',
                    environment: 'production',
                    server: 'hel-1',
                    reason: 'runs',
                    message: 'worker (shop / production) still runs on hel-1.',
                    href: '#',
                },
            ],
        },
    },
    {
        id: 'handover',
        title: 'Hand-over, ready',
        props: {
            ...base,
            move: {
                ...joined,
                status: 'ready_to_hand_over',
                receiverWaiting: true,
            },
            dns: records,
        },
    },
    {
        id: 'handed-over',
        title: 'Old dashboard after the hand-over',
        props: {
            ...base,
            move: {
                ...joined,
                status: 'handed_over',
                newUrl: 'https://dash.acme.dev',
                handedOverAt: NOW,
            },
            serverMove: {
                ...serverMove,
                status: 'completed',
                done: items.length,
                failed: 0,
                items: items.map((entry) => ({ ...entry, status: 'done' })),
            },
            dnsSwitch,
        },
    },
    {
        id: 'handover-ready',
        title: 'The new server is starting the dashboard',
        props: {
            ...base,
            move: {
                ...joined,
                status: 'handover_ready',
                dumpSize: '123 MB',
            },
        },
    },
];

function device(kind: App.Enums.DeviceKind): App.Data.Servers.DeviceData {
    return {
        kind,
        detected: kind,
        overridden: false,
        model: null,
        provider: null,
        providerName: null,
        virtualized: kind === 'cloud',
    };
}

const checklist: Checklist = {
    startedAt: NOW,
    previousUrl: 'https://dash.acme.dev',
    url: 'https://dash.acme.dev',
    previous: {
        name: 'hel-1',
        addresses: [OLD_IP],
        device: device('dedicated'),
    },
    current: { name: 'fsn-2', addresses: [NEW_IP], device: device('cloud') },
    checks: {
        expectedAddresses: [NEW_IP],
        dns: records.map((record) => ({
            label:
                record.kind === 'dashboard'
                    ? 'Dashboard'
                    : record.kind === 'apps'
                      ? 'Apps'
                      : 'Domain',
            host: record.host,
            check: record.check,
        })),
        servers: [],
        images: null,
        dnsOk: false,
        imagesOk: false,
        serversOk: true,
    },
    needsRecoveryKit: true,
    openSteps: ['dns', 'images'],
    dnsSwitch: null,
};

const checklistWithSwitch: Checklist = {
    ...checklist,
    dnsSwitch: {
        ...dnsSwitch,
        reachBeforeDns: null,
        pointed: 1,
        records: dnsSwitch.records.map((record) =>
            record.kind === 'dashboard'
                ? { ...record, check: records[0].check }
                : record,
        ),
    },
};

function Frame({
    id,
    title,
    children,
}: {
    id: string;
    title: string;
    children: ReactNode;
}) {
    return (
        <div id={id} className="flex scroll-mt-8 flex-col gap-3">
            <p className="type-label text-muted-foreground">{title}</p>
            <div className="mx-auto flex w-full max-w-[800px] flex-col gap-8 rounded-[14px] border bg-background p-4 sm:p-8">
                {children}
            </div>
        </div>
    );
}

export function MoveStatesGallery() {
    return (
        <div className="flex flex-col gap-12">
            {STATES.map((state) => (
                <Frame
                    key={state.id}
                    id={`move-${state.id}`}
                    title={state.title}
                >
                    <ControlPlaneMoveSteps {...state.props} />
                </Frame>
            ))}
            <Frame id="move-checklist" title="New dashboard: checklist">
                <MoveChecklist
                    checklist={checklistWithSwitch}
                    onCheck={() => {}}
                />
            </Frame>
            <Frame
                id="move-checklist-no-device"
                title="New dashboard: checklist, machines without device data"
            >
                <MoveChecklist
                    checklist={{
                        ...checklistWithSwitch,
                        previous: {
                            name: 'hel-1',
                            addresses: [OLD_IP],
                            device: null,
                        },
                        current: {
                            name: 'fsn-2',
                            addresses: [NEW_IP],
                            device: null,
                        },
                        openSteps: [],
                    }}
                    onCheck={() => {}}
                />
            </Frame>
            <Frame id="move-server" title="Server move, two failed">
                <MoveProgress
                    move={{ ...serverMove, controlPlane: false }}
                    retryForm={stop}
                    stopForm={stop}
                />
            </Frame>
            <Frame
                id="move-moved"
                title="Every other page of the old dashboard"
            >
                <MovedScreen movedTo="https://dash.acme.dev" summaryHref="#" />
            </Frame>
        </div>
    );
}
