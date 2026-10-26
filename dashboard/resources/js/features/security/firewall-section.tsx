import { Link } from "@inertiajs/react";
import { Eye, ShieldCheck, TriangleAlert } from "lucide-react";
import { useState } from "react";
import { toast } from "sonner";
import { formatCompact } from "@/components/charts/format";
import { StackedBars } from "@/components/charts/stacked-bars";
import type { StackedSeries } from "@/components/charts/stacked-bars";
import { Meter } from "@/components/charts/usage-meter";
import { Callout } from "@/components/patterns/callout";
import { MetaLine } from "@/components/patterns/meta-line";
import { Section } from "@/components/patterns/section";
import { SheetSection, SidePanel } from "@/components/patterns/sheet";
import { TimeAgo } from "@/components/time-ago";
import { Button } from "@/components/ui/button";
import { requestJson } from "@/features/projects/http";
import { cn } from "@/lib/utils";
import { card, CATEGORY_LABEL, plural, SERIES_COLOR } from "./format";

type Firewall = App.Data.Security.WafOverviewData;
type WafEvent = App.Data.Security.WafEventData;
type Summary = App.Data.Security.SecuritySummaryData;

const RANGE_WORDS: Record<string, string> = {
    "24h": "today",
    "7d": "this week",
    "30d": "this month",
};

/** A category's colour: palette slots for the four named kinds, grey else. */
export function categoryColor(category: string): string {
    return SERIES_COLOR[category] ?? "var(--chart-other)";
}

/**
 * The Security page's web firewall (docs/22 §9.2 part 3): blocked
 * requests over time with the attack mix beside them, services watching
 * in Log only, the latest blocks and the most targeted services.
 */
export function FirewallSection({
    firewall,
    summary,
    canManage,
    onChanged,
}: {
    firewall: Firewall;
    summary: Summary;
    canManage: boolean;
    onChanged: () => void;
}) {
    const [event, setEvent] = useState<WafEvent | null>(null);
    const total = firewall.blocked + firewall.detected;
    const outdated = firewall.edges.filter((edge) => edge.state !== "error");

    return (
        <Section
            title="Web firewall"
            description={
                firewall.ruleSet
                    ? `${firewall.ruleSet} on every edge`
                    : undefined
            }
        >
            <div className="flex flex-col gap-4">
                {firewall.edges
                    .filter((edge) => edge.state === "error")
                    .map((edge) => (
                        <Callout
                            key={edge.serverId}
                            tone="warning"
                            title={`The web firewall isn’t running on ${edge.server}`}
                        >
                            {edge.message ??
                                "The server refused the firewall settings and serves requests without it."}
                        </Callout>
                    ))}
                {outdated.length > 0 && (
                    <Callout
                        tone="warning"
                        title={`Update ${listNames(outdated.map((edge) => edge.server))} to run the web firewall`}
                    >
                        {outdated.length === 1
                            ? "This server’s edge doesn’t include the firewall yet, so it serves requests unchecked, whatever mode a service is set to."
                            : "These servers’ edges don’t include the firewall yet, so they serve requests unchecked, whatever mode a service is set to."}
                    </Callout>
                )}

                {total === 0 ? (
                    <QuietFirewall firewall={firewall} summary={summary} />
                ) : (
                    <ChartCard firewall={firewall} />
                )}

                {firewall.watching.length > 0 && (
                    <Watching
                        firewall={firewall}
                        canManage={canManage}
                        onChanged={onChanged}
                    />
                )}

                {(firewall.recent.length > 0 ||
                    firewall.services.length > 0) && (
                    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                        {firewall.recent.length > 0 && (
                            <RecentBlocks
                                events={firewall.recent}
                                networks={firewall.networksVisible}
                                onOpen={setEvent}
                                className={
                                    firewall.services.length > 0
                                        ? "lg:col-span-2"
                                        : "lg:col-span-3"
                                }
                            />
                        )}
                        {firewall.services.length > 0 && (
                            <MostTargeted
                                firewall={firewall}
                                className={
                                    firewall.recent.length > 0
                                        ? ""
                                        : "lg:col-span-3"
                                }
                            />
                        )}
                    </div>
                )}
            </div>

            <EventSheet
                event={event}
                onOpenChange={(open) => !open && setEvent(null)}
                canManage={canManage}
            />
        </Section>
    );
}

/** No attacks in the range: the protection that is running, calmly. */
function QuietFirewall({
    firewall,
    summary,
}: {
    firewall: Firewall;
    summary: Summary;
}) {
    return (
        <div
            data-firewall-quiet
            className={cn(
                card,
                "flex flex-col gap-5 p-5 sm:flex-row sm:items-center sm:gap-6 sm:p-6",
            )}
        >
            <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-recessed text-muted-foreground">
                <ShieldCheck aria-hidden="true" className="size-5" />
            </span>
            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                <p className="type-heading">
                    No attacks blocked {RANGE_WORDS[firewall.range]}
                </p>
                <p className="type-body text-muted-foreground">
                    {summary.routedServices === 0
                        ? "Services with a domain are protected as soon as they go live."
                        : `Requests to ${plural(summary.protectedServices, "public service")} pass the firewall first.`}
                </p>
            </div>
            {summary.routedServices > 0 && (
                <div className="flex w-full shrink-0 flex-col gap-1.5 sm:w-56">
                    <span className="flex items-baseline justify-between type-small text-muted-foreground">
                        <span>Blocking attacks</span>
                        <span className="tabular-nums">
                            {summary.protectedServices} of{" "}
                            {summary.routedServices}
                        </span>
                    </span>
                    <Meter
                        value={summary.protectedServices}
                        max={summary.routedServices}
                        label="Public services that block attacks"
                    />
                </div>
            )}
        </div>
    );
}

/**
 * Blocked requests per bucket as one signal series (plus "Would have been
 * blocked" hatched grey while a service is in Log only), and the attack
 * mix beside it.
 */
function ChartCard({ firewall }: { firewall: Firewall }) {
    const times = firewall.timestamps.map((t) => Date.parse(t));
    const unit = firewall.range === "30d" ? "day" : "hour";
    const blocked = times.map((_, i) =>
        firewall.series
            .filter((s) => s.key !== "detected")
            .reduce((sum, s) => sum + (s.values[i] ?? 0), 0),
    );
    const detected =
        firewall.series.find((s) => s.key === "detected")?.values ?? [];
    const series: StackedSeries[] = [
        {
            key: "blocked",
            label: "Blocked",
            color: "var(--signal)",
            values: blocked,
        },
    ];

    if (firewall.watching.length > 0) {
        series.push({
            key: "detected",
            label: "Would have been blocked",
            color: "var(--chart-other)",
            hatched: true,
            values: detected,
        });
    }

    return (
        <div
            className={cn(
                card,
                "grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_20rem]",
            )}
        >
            <div className="flex min-w-0 flex-col gap-1 p-5 sm:p-6">
                <h3 className="type-label text-muted-foreground">
                    Requests per {unit}
                </h3>
                <StackedBars
                    title={`Blocked requests per ${unit}`}
                    times={times}
                    bucketMs={firewall.bucketSeconds * 1000}
                    series={series}
                    height={240}
                />
            </div>
            <AttackMix firewall={firewall} />
        </div>
    );
}

/**
 * The kinds of attack as one segmented bar and a ranked list: the four
 * kinds with a palette slot in colour, the rest folded into grey "Other".
 */
function AttackMix({ firewall }: { firewall: Firewall }) {
    const kinds = firewall.categories
        .map((c) => ({ ...c, count: c.blocked + c.detected }))
        .filter((c) => c.count > 0)
        .sort((a, b) => b.count - a.count);
    const total = kinds.reduce((sum, c) => sum + c.count, 0);
    const named = kinds.filter(
        (c) => c.category !== "other" && c.category in SERIES_COLOR,
    );
    const rest = kinds.filter((c) => !named.includes(c));
    const rows = [
        ...named.map((c) => ({
            key: c.category,
            label: c.label,
            count: c.count,
            color: categoryColor(c.category),
            detail: null as string | null,
        })),
        ...(rest.length > 0
            ? [
                  {
                      key: "other",
                      label: "Other",
                      count: rest.reduce((sum, c) => sum + c.count, 0),
                      color: "var(--chart-other)",
                      detail: rest.map((c) => c.label).join(", "),
                  },
              ]
            : []),
    ];

    if (total === 0) {
        return null;
    }

    return (
        <div className="flex min-w-0 flex-col gap-4 border-t p-5 sm:p-6 lg:border-t-0 lg:border-l">
            <h3 className="type-label text-muted-foreground">Attack mix</h3>
            <div
                role="img"
                aria-label={`Attack mix: ${rows.map((r) => `${r.label} ${share(r.count, total)}`).join(", ")}`}
                className="flex h-2 w-full gap-0.5 overflow-hidden rounded-full"
            >
                {rows.map((r) => (
                    <span
                        key={r.key}
                        className="h-full first:rounded-l-full last:rounded-r-full"
                        style={{
                            width: `${(r.count / total) * 100}%`,
                            minWidth: 3,
                            backgroundColor: r.color,
                        }}
                    />
                ))}
            </div>
            <ul className="flex flex-col gap-3">
                {rows.map((r) => (
                    <li key={r.key} className="flex flex-col">
                        <span className="flex items-center gap-2.5 type-body">
                            <span
                                aria-hidden="true"
                                className="size-2.5 shrink-0 rounded-[3px]"
                                style={{ backgroundColor: r.color }}
                            />
                            <span className="min-w-0 flex-1 truncate">
                                {r.label}
                            </span>
                            <span className="tabular-nums">
                                {formatCompact(r.count)}
                            </span>
                            <span className="w-10 text-right type-small text-muted-foreground tabular-nums">
                                {share(r.count, total)}
                            </span>
                        </span>
                        {r.detail && (
                            <span className="pl-5 type-small text-muted-foreground">
                                {r.detail}
                            </span>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}

function share(value: number, total: number): string {
    const percent = total > 0 ? (value / total) * 100 : 0;

    return percent > 0 && percent < 1 ? "<1%" : `${Math.round(percent)}%`;
}

function MostTargeted({
    firewall,
    className,
}: {
    firewall: Firewall;
    className?: string;
}) {
    const rows = firewall.services.slice(0, 5);
    const top = Math.max(1, ...rows.map((s) => s.blocked + s.detected));

    return (
        <div className={cn(card, "flex min-w-0 flex-col", className)}>
            <h3 className="px-5 pt-4 pb-3 type-label text-muted-foreground">
                Most targeted
            </h3>
            <ul className="flex flex-col gap-3.5 px-5 pb-5">
                {rows.map((s) => {
                    const count = s.blocked + s.detected;

                    return (
                        <li
                            key={s.service.id + s.service.environmentId}
                            className="flex flex-col gap-1.5"
                        >
                            <span className="flex min-w-0 items-baseline gap-2">
                                <Link
                                    href={s.service.href}
                                    className="min-w-0 truncate type-body font-medium hover:underline"
                                >
                                    {s.service.name}
                                </Link>
                                <span className="min-w-0 truncate type-small text-muted-foreground">
                                    {s.service.projectName}
                                </span>
                                <span className="ml-auto type-body tabular-nums">
                                    {formatCompact(count)}
                                </span>
                            </span>
                            <Meter
                                value={count}
                                max={top}
                                label={`${s.service.name}: ${count} requests`}
                                height={4}
                            />
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

/**
 * "Watching, not blocking yet": Log only services with what they would
 * have blocked, and a one-click switch.
 */
function Watching({
    firewall,
    canManage,
    onChanged,
}: {
    firewall: Firewall;
    canManage: boolean;
    onChanged: () => void;
}) {
    const [busy, setBusy] = useState<string | null>(null);

    const block = async (url: string, name: string) => {
        setBusy(url);

        try {
            await requestJson("patch", url, { mode: "block" });
            toast.success(`${name} now blocks attacks`);
            onChanged();
        } catch (error) {
            toast.error((error as Error).message);
        } finally {
            setBusy(null);
        }
    };

    return (
        <div className={cn(card, "flex flex-col overflow-hidden")}>
            <h3 className="flex items-center gap-2 border-b px-5 py-3 type-label text-muted-foreground">
                <Eye aria-hidden="true" className="size-4" />
                Watching, not blocking yet
            </h3>
            <ul className="divide-y">
                {firewall.watching.map((w) => (
                    <li
                        key={w.service.id + w.service.environmentId}
                        className="flex flex-col gap-3 px-5 py-3.5 sm:flex-row sm:items-center sm:gap-6"
                    >
                        <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                            <span className="type-body">
                                <span className="font-medium">
                                    {w.service.name}
                                </span>
                                <span className="text-muted-foreground">
                                    {" "}
                                    in {w.service.environmentName}{" "}
                                </span>
                                {w.detected > 0
                                    ? `would have blocked ${plural(w.detected, "request")} in the last 7 days`
                                    : "would have blocked nothing in the last 7 days"}
                            </span>
                            {(w.categories.length > 0 || w.topPath) && (
                                <MetaLine className="type-small text-muted-foreground">
                                    {w.categories.length > 0
                                        ? w.categories.join(", ")
                                        : null}
                                    {w.topPath ? (
                                        <code className="type-mono-small">
                                            {w.topPath}
                                        </code>
                                    ) : null}
                                </MetaLine>
                            )}
                        </div>
                        {canManage && w.settingsUrl && (
                            <Button
                                size="sm"
                                variant="outline"
                                className="self-start sm:self-center"
                                disabled={busy === w.settingsUrl}
                                onClick={() =>
                                    void block(
                                        w.settingsUrl as string,
                                        w.service.name,
                                    )
                                }
                            >
                                Turn on blocking
                            </Button>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}

const RECENT_SHOWN = 8;

function RecentBlocks({
    events,
    networks,
    onOpen,
    className,
}: {
    events: WafEvent[];
    networks: boolean;
    onOpen: (event: WafEvent) => void;
    className?: string;
}) {
    const [expanded, setExpanded] = useState(false);
    const shown = expanded ? events : events.slice(0, RECENT_SHOWN);

    return (
        <div
            className={cn(
                card,
                "flex min-w-0 flex-col overflow-hidden",
                className,
            )}
        >
            <h3 className="px-5 pt-4 pb-2 type-label text-muted-foreground">
                Recent blocks
            </h3>
            <ul className="flex flex-col pb-2">
                {shown.map((event) => {
                    const category = event.categories[0] ?? "other";

                    return (
                        <li key={event.id}>
                            <button
                                type="button"
                                data-waf-event={event.id}
                                onClick={() => onOpen(event)}
                                className="grid w-full min-w-0 grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-0.5 px-5 py-2 text-left hover:bg-hover focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-signal md:grid-cols-[5.5rem_minmax(0,1fr)_12.5rem_auto]"
                            >
                                <span className="order-4 text-right type-small text-faint tabular-nums md:order-none md:text-left">
                                    <TimeAgo value={event.at} />
                                </span>
                                <code className="min-w-0 truncate type-mono-small">
                                    <span className="text-muted-foreground">
                                        {event.method}
                                    </span>{" "}
                                    {event.path}
                                </code>
                                <span className="order-3 flex min-w-0 items-center gap-2 type-small md:order-none">
                                    <span
                                        aria-hidden="true"
                                        className="size-2 shrink-0 rounded-[2px]"
                                        style={{
                                            backgroundColor:
                                                categoryColor(category),
                                        }}
                                    />
                                    <span className="truncate">
                                        {CATEGORY_LABEL[category] ?? "Other"}
                                        {event.action === "detected" && (
                                            <span className="text-muted-foreground">
                                                , logged
                                            </span>
                                        )}
                                    </span>
                                </span>
                                <span className="order-2 flex min-w-0 items-center justify-end gap-3 type-small text-muted-foreground md:order-none">
                                    <span className="truncate">
                                        {event.service.name}
                                    </span>
                                    {networks && event.clientNetwork && (
                                        <code className="hidden w-36 truncate text-right type-mono-small text-faint xl:inline">
                                            {event.clientNetwork}
                                        </code>
                                    )}
                                </span>
                            </button>
                        </li>
                    );
                })}
            </ul>
            {events.length > RECENT_SHOWN && (
                <button
                    type="button"
                    onClick={() => setExpanded(!expanded)}
                    aria-expanded={expanded}
                    className="w-full border-t px-5 py-2.5 text-left type-small text-muted-foreground hover:bg-hover hover:text-foreground"
                >
                    {expanded
                        ? "Show fewer"
                        : `Show ${events.length - RECENT_SHOWN} more`}
                </button>
            )}
        </div>
    );
}

/**
 * One blocked request: its rules with CRS's messages, the matched parts,
 * the score, and "Allow requests like this" (docs/22 §6.4).
 */
export function EventSheet({
    event,
    onOpenChange,
    canManage,
}: {
    event: WafEvent | null;
    onOpenChange: (open: boolean) => void;
    canManage: boolean;
}) {
    const [busy, setBusy] = useState(false);
    const draft = event?.exclusion ?? null;

    const allow = async () => {
        if (!draft) {
            return;
        }

        setBusy(true);

        try {
            await requestJson("post", draft.exclusionsUrl, {
                rule: draft.rule,
                path: draft.path,
                target: draft.target,
            });
            toast.success("Requests like this are allowed now");
            onOpenChange(false);
        } catch (error) {
            toast.error((error as Error).message);
        } finally {
            setBusy(false);
        }
    };

    return (
        <SidePanel
            open={event !== null}
            onOpenChange={onOpenChange}
            size="md"
            title={
                event ? (
                    <code className="type-mono">
                        {event.method} {event.path}
                    </code>
                ) : (
                    ""
                )
            }
            description={
                event
                    ? `${event.action === "blocked" ? "Blocked" : "Logged in Log only"} for ${event.service.name}, score ${event.score}`
                    : undefined
            }
        >
            {event && (
                <>
                    <SheetSection title="Rules">
                        <ul className="flex flex-col gap-2">
                            {event.rules.map((rule) => (
                                <li
                                    key={rule.id}
                                    className="flex items-baseline gap-3 type-small"
                                >
                                    <code className="type-mono-small text-muted-foreground">
                                        {rule.id}
                                    </code>
                                    <span>{rule.message}</span>
                                </li>
                            ))}
                        </ul>
                    </SheetSection>
                    <SheetSection title="Request">
                        <dl className="grid grid-cols-[8rem_1fr] gap-x-4 gap-y-2 type-small">
                            <dt className="text-muted-foreground">Time</dt>
                            <dd>{new Date(event.at).toLocaleString("en")}</dd>
                            <dt className="text-muted-foreground">Matched</dt>
                            <dd className="font-mono">
                                {event.targets.join(", ") || "Request"}
                            </dd>
                            {event.clientNetwork && (
                                <>
                                    <dt className="text-muted-foreground">
                                        Client network
                                    </dt>
                                    <dd className="font-mono">
                                        {event.clientNetwork}
                                    </dd>
                                </>
                            )}
                            <dt className="text-muted-foreground">Reference</dt>
                            <dd className="font-mono [overflow-wrap:anywhere]">
                                {event.reference}
                            </dd>
                        </dl>
                    </SheetSection>
                    {draft && canManage && (
                        <SheetSection
                            title="Allow requests like this"
                            description="For legitimate requests the firewall blocked."
                        >
                            <div className="flex flex-col gap-3">
                                <p className="type-small text-muted-foreground">
                                    Rule{" "}
                                    <code className="font-mono">
                                        {draft.rule}
                                    </code>{" "}
                                    stops checking{" "}
                                    {draft.target ? (
                                        <code className="font-mono">
                                            {draft.target}
                                        </code>
                                    ) : (
                                        "requests"
                                    )}
                                    {draft.path ? (
                                        <>
                                            {" "}
                                            under{" "}
                                            <code className="font-mono">
                                                {draft.path}
                                            </code>
                                        </>
                                    ) : null}{" "}
                                    on {event.service.name}.
                                </p>
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        size="sm"
                                        disabled={busy}
                                        onClick={() => void allow()}
                                    >
                                        Add exception
                                    </Button>
                                    <Button size="sm" variant="ghost" asChild>
                                        <Link href={draft.settingsHref}>
                                            Edit first
                                        </Link>
                                    </Button>
                                </div>
                            </div>
                        </SheetSection>
                    )}
                    {!draft && (
                        <SheetSection>
                            <p className="flex items-center gap-2 type-small text-muted-foreground">
                                <TriangleAlert
                                    aria-hidden="true"
                                    className="size-3.5"
                                />
                                These rules can't be excepted.
                            </p>
                        </SheetSection>
                    )}
                </>
            )}
        </SidePanel>
    );
}

export function listNames(names: string[]): string {
    return names.length <= 1
        ? (names[0] ?? "")
        : `${names.slice(0, -1).join(", ")} and ${names[names.length - 1]}`;
}
