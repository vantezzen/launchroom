import { ShieldAlert, ShieldCheck } from "lucide-react";
import type { ReactNode } from "react";
import { formatCompact } from "@/components/charts/format";
import { Sparkline } from "@/components/charts/sparkline";
import { Meter } from "@/components/charts/usage-meter";
import { MetaLine } from "@/components/patterns/meta-line";
import { StatusIcon } from "@/components/status";
import type { StatusState } from "@/components/status";
import { relativeTime } from "@/components/time-ago";
import { delta } from "@/features/metrics/format";
import { cn } from "@/lib/utils";
import { card, plural } from "./format";
import type { ActionGroup } from "./shape";

type PageData = App.Data.Security.SecurityPageData;

const RANGE_WORDS: Record<string, string> = {
    "24h": "in the last 24 hours",
    "7d": "in the last 7 days",
    "30d": "in the last 30 days",
};

/**
 * The top of the Security page (docs/22 §9.2 part 1): the verdict ("am I
 * okay?") with the protections at work as three numbers underneath.
 */
export function SecurityHero({
    data,
    groups,
    range,
}: {
    data: PageData;
    groups: ActionGroup[];
    range: string;
}) {
    const needs = groups.length;
    const services = new Set(
        groups.flatMap((g) =>
            g.findings.map((f) => f.service.id + f.service.environmentId),
        ),
    ).size;
    const exploited = groups.filter((g) => g.knownExploited).length;

    return (
        <section
            aria-label="Security status"
            data-security-hero={needs > 0 ? "action" : "clear"}
            className={cn(card, "overflow-hidden")}
        >
            <div className="flex items-center gap-4 px-5 py-5 sm:gap-5 sm:px-6 sm:py-6">
                <span
                    aria-hidden="true"
                    className={cn(
                        "flex size-12 shrink-0 items-center justify-center rounded-[14px] sm:size-14",
                        needs > 0
                            ? "bg-status-error-soft text-status-error"
                            : "bg-status-ok-soft text-status-ok",
                    )}
                >
                    {needs > 0 ? (
                        <ShieldAlert className="size-6 sm:size-7" />
                    ) : (
                        <ShieldCheck className="size-6 sm:size-7" />
                    )}
                </span>
                <div className="flex min-w-0 flex-col gap-1">
                    <p className="type-title text-[22px] leading-7 sm:text-[26px] sm:leading-8">
                        {needs > 0
                            ? `${plural(needs, "vulnerability", "vulnerabilities")} ${needs === 1 ? "needs" : "need"} you`
                            : "Nothing needs you"}
                    </p>
                    <MetaLine className="type-body text-muted-foreground">
                        {needs > 0 ? `In ${plural(services, "service")}` : null}
                        {exploited > 0 ? (
                            <span className="inline-flex items-center gap-1.5 text-foreground">
                                <StatusIcon state="warning" />
                                {exploited === 1
                                    ? "One is known to be exploited"
                                    : `${exploited} are known to be exploited`}
                            </span>
                        ) : null}
                        <CheckedLine data={data} />
                    </MetaLine>
                </div>
                <Protections data={data} />
            </div>

            <div className="grid grid-cols-1 border-t max-md:divide-y md:grid-cols-3 md:divide-x">
                <PackagesMetric data={data} />
                <BlockedMetric data={data} range={range} />
                <ProtectedMetric data={data} />
            </div>
        </section>
    );
}

const FEED_WORDS: Record<string, string> = {
    osv: "Vulnerability checks",
    kev: "Known exploited list",
    eol: "Release cycles",
};

/**
 * What protects the team right now, one status row each: the public data
 * sources (docs/22 §4) and the web firewall.
 */
function Protections({ data }: { data: PageData }) {
    const rows: {
        key: string;
        label: string;
        state: StatusState;
        word: string;
    }[] = data.feeds.map((feed) => {
        const failing =
            feed.failingSince !== null &&
            Date.now() - Date.parse(feed.failingSince) > 86_400_000;

        return {
            key: feed.feed,
            label: FEED_WORDS[feed.feed] ?? feed.label,
            state: !feed.enabled ? "idle" : failing ? "warning" : "active",
            word: !feed.enabled ? "Off" : failing ? "Unreachable" : "On",
        };
    });
    const s = data.summary;
    // An edge that can't run the firewall is not a mode choice: say so first.
    const edges = data.firewall.edges;

    rows.push({
        key: "waf",
        label: "Web firewall",
        state:
            edges.length > 0
                ? "warning"
                : s.routedServices === 0
                  ? "idle"
                  : s.protectedServices > 0
                    ? "active"
                    : "warning",
        word:
            edges.length > 0
                ? edges.every((edge) => edge.state === "unsupported")
                    ? "Needs update"
                    : "Not running"
                : s.routedServices === 0
                  ? "Ready"
                  : s.protectedServices > 0
                    ? "On"
                    : s.watchingServices > 0
                      ? "Log only"
                      : "Off",
    });

    return (
        <ul
            aria-label="Protections"
            className="ml-auto hidden w-60 shrink-0 flex-col gap-1.5 border-l pl-6 lg:flex"
        >
            {rows.map((row) => (
                <li
                    key={row.key}
                    className="flex items-center gap-2 type-small"
                >
                    <StatusIcon state={row.state} />
                    <span className="min-w-0 flex-1 truncate">{row.label}</span>
                    <span className="text-muted-foreground">
                        <span className="sr-only">: </span>
                        {row.word}
                    </span>
                </li>
            ))}
        </ul>
    );
}

/** "Checked 28 minutes ago", or why the checks aren't current. */
function CheckedLine({ data }: { data: PageData }) {
    const osv = data.feeds.find((feed) => feed.feed === "osv");

    if (data.checksOff || !osv?.enabled) {
        return <span>Vulnerability checks are off</span>;
    }

    if (
        osv.failingSince &&
        Date.now() - Date.parse(osv.failingSince) > 86_400_000
    ) {
        return (
            <span className="inline-flex items-center gap-1.5 text-foreground">
                <StatusIcon state="warning" />
                Can’t reach {osv.host} since {relativeTime(osv.failingSince)}
            </span>
        );
    }

    return (
        <span>
            {data.checkedAt
                ? `Checked ${relativeTime(data.checkedAt)}`
                : "Checking packages for the first time"}
        </span>
    );
}

function Metric({
    label,
    value,
    unit,
    hint,
    visual,
}: {
    label: string;
    value: ReactNode;
    unit?: ReactNode;
    hint: ReactNode;
    visual?: ReactNode;
}) {
    return (
        <div className="flex min-w-0 flex-col gap-2 px-5 py-4 sm:px-6 sm:py-5">
            <span className="type-label text-muted-foreground">{label}</span>
            <div className="flex min-w-0 items-end justify-between gap-4">
                <span className="flex items-baseline gap-1.5">
                    <span className="type-stat tabular-nums">{value}</span>
                    {unit && (
                        <span className="type-body text-muted-foreground">
                            {unit}
                        </span>
                    )}
                </span>
                {visual && (
                    <div className="mb-1 w-28 min-w-0 shrink lg:w-36">
                        {visual}
                    </div>
                )}
            </div>
            <span className="type-small text-muted-foreground">{hint}</span>
        </div>
    );
}

function PackagesMetric({ data }: { data: PageData }) {
    const s = data.summary;
    const stale =
        data.checkedAt !== null &&
        Date.now() - Date.parse(data.checkedAt) > 86_400_000;

    if (s.packages === 0 && s.pendingInventories > 0) {
        return (
            <Metric
                label="Packages watched"
                value="…"
                hint={`Reading packages of ${plural(s.pendingInventories, "image")}`}
            />
        );
    }

    return (
        <Metric
            label="Packages watched"
            value={s.packages.toLocaleString("en")}
            hint={
                <span className="inline-flex items-center gap-1.5">
                    {stale && <StatusIcon state="warning" />}
                    {s.pendingInventories > 0
                        ? `In ${plural(s.servicesChecked, "service")}, reading ${plural(s.pendingInventories, "more image")}`
                        : `In ${plural(s.servicesChecked, "service")}`}
                </span>
            }
        />
    );
}

function BlockedMetric({ data, range }: { data: PageData; range: string }) {
    const s = data.summary;
    const change = delta(s.blocked, s.previousBlocked);

    return (
        <Metric
            label="Attacks blocked"
            value={formatCompact(s.blocked)}
            unit={
                change ? (
                    <span className="type-small tabular-nums">
                        {change.label}
                    </span>
                ) : undefined
            }
            hint={RANGE_WORDS[range] ?? ""}
            visual={
                s.blocked > 0 ? (
                    <Sparkline
                        values={s.blockedSeries}
                        label={`Attacks blocked ${RANGE_WORDS[range] ?? ""}`}
                        height={28}
                        area
                        min={0}
                    />
                ) : undefined
            }
        />
    );
}

function ProtectedMetric({ data }: { data: PageData }) {
    const s = data.summary;
    let state: StatusState | null = null;
    let hint = "No public services yet";

    if (s.routedServices > 0) {
        if (s.watchingServices > 0) {
            state = "warning";
            hint = `${s.watchingServices} only log attacks`;
        } else if (s.protectedServices < s.routedServices) {
            hint = `${s.routedServices - s.protectedServices} with the firewall off`;
        } else {
            state = "active";
            hint = "Every public service blocks attacks";
        }
    }

    return (
        <Metric
            label="Protected services"
            value={s.protectedServices}
            unit={s.routedServices > 0 ? `of ${s.routedServices}` : undefined}
            hint={
                <span className="inline-flex items-center gap-1.5">
                    {state && <StatusIcon state={state} />}
                    {hint}
                </span>
            }
            visual={
                s.routedServices > 0 ? (
                    <Meter
                        value={s.protectedServices}
                        max={s.routedServices}
                        label="Public services that block attacks"
                        height={6}
                    />
                ) : undefined
            }
        />
    );
}
