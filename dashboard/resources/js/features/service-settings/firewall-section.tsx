import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Eye, Plus, ShieldCheck, ShieldOff, Trash2 } from "lucide-react";
import { useState } from "react";
import { toast } from "sonner";
import { formatCompact } from "@/components/charts/format";
import { Sparkline } from "@/components/charts/sparkline";
import { Callout } from "@/components/patterns/callout";
import { ChoiceTiles } from "@/components/patterns/choice-tiles";
import { ConfirmPopover } from "@/components/patterns/confirm-popover";
import { TimeAgo } from "@/components/time-ago";
import { Button } from "@/components/ui/button";
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import { requestJson } from "@/features/projects/http";
import { SettingsCard, SettingsRow } from "@/features/settings/settings-card";
import { show as firewallRoute } from "@/routes/security/firewall";
import { listNames } from "@/features/security/firewall-section";
import { SettingsPage } from "./section";
import type { SettingsContext } from "./settings-context";

/** Request body inspection limits (docs/22 §6.7); 32 KiB is the default. */
const BODY_LABEL: Record<string, string> = {
    "32": "32 KiB",
    "128": "128 KiB",
    "1024": "1 MiB",
};

type Firewall = App.Data.Security.ServiceFirewallData;
type Mode = "off" | "log" | "block";

/**
 * Settings → Web firewall (docs/22 §9.6): the mode as three tiles, the
 * last 7 days for this service, exceptions for false positives, stricter
 * rules under Advanced. Changes apply right away; they aren't staged.
 */
export function FirewallSection({ context }: { context: SettingsContext }) {
    const [project, environment, service] = context.route;
    const client = useQueryClient();
    const key = ["service-firewall", project, environment, service];
    const { data } = useQuery({
        queryKey: key,
        queryFn: ({ signal }) =>
            requestJson<Firewall>(
                "get",
                firewallRoute({ project, environment, service }).url,
                undefined,
                signal,
            ),
        refetchInterval: 60_000,
    });
    const refresh = () => void client.invalidateQueries({ queryKey: key });
    const [saving, setSaving] = useState(false);

    const update = async (
        body: { mode?: Mode; paranoia?: number; inspectBodyKiB?: number },
        message: string,
    ) => {
        if (!data) {
            return;
        }

        setSaving(true);

        try {
            await requestJson("patch", data.updateUrl, body);
            toast.success(message);
            refresh();
        } catch (error) {
            toast.error((error as Error).message);
        } finally {
            setSaving(false);
        }
    };

    const outdated = (data?.edges ?? []).filter(
        (edge) => edge.state !== "error",
    );

    return (
        <SettingsPage
            title="Web firewall"
            description="Checks requests to this service for common attacks before they reach it."
        >
            {!data ? (
                <div
                    aria-hidden="true"
                    className="h-40 animate-skeleton-in rounded-xl bg-recessed"
                />
            ) : (
                <>
                    {!data.routed && (
                        <Callout title="No public domain yet">
                            The setting applies once the service has a domain.
                        </Callout>
                    )}
                    {data.edges
                        .filter((edge) => edge.state === "error")
                        .map((edge) => (
                            <Callout
                                key={edge.serverId}
                                tone="warning"
                                title={`The firewall isn’t running on ${edge.server}`}
                            >
                                {edge.message ??
                                    "The server refused the firewall settings."}
                            </Callout>
                        ))}
                    {outdated.length > 0 && (
                        <Callout
                            tone="warning"
                            title={`Update ${listNames(outdated.map((edge) => edge.server))} to protect this service`}
                        >
                            {outdated.length === 1
                                ? "Its edge doesn’t include the firewall yet, so it serves this service’s requests unchecked whatever the mode below says."
                                : "Their edges don’t include the firewall yet, so they serve this service’s requests unchecked whatever the mode below says."}
                        </Callout>
                    )}

                    <ChoiceTiles<Mode>
                        label="Mode"
                        columns={3}
                        value={data.mode as Mode}
                        onChange={(mode) =>
                            data.canManage && !saving && mode !== data.mode
                                ? void update(
                                      { mode },
                                      mode === "block"
                                          ? "Attacks are blocked now"
                                          : mode === "log"
                                            ? "Attacks are logged now"
                                            : "The firewall is off",
                                  )
                                : undefined
                        }
                        options={[
                            {
                                value: "off",
                                label: "Off",
                                description:
                                    "Requests reach the service unchecked",
                                media: (
                                    <ShieldOff
                                        aria-hidden="true"
                                        className="size-5"
                                    />
                                ),
                                disabled: !data.canManage,
                            },
                            {
                                value: "log",
                                label: "Log only",
                                description: "Attacks are counted, not refused",
                                media: (
                                    <Eye
                                        aria-hidden="true"
                                        className="size-5"
                                    />
                                ),
                                disabled: !data.canManage,
                            },
                            {
                                value: "block",
                                label: "Block",
                                description:
                                    "Requests that match attack rules are refused",
                                media: (
                                    <ShieldCheck
                                        aria-hidden="true"
                                        className="size-5"
                                    />
                                ),
                                disabled: !data.canManage,
                            },
                        ]}
                    />

                    {data.mode === "log" &&
                        data.watching &&
                        data.watching.detected > 0 && (
                            <Callout
                                title={`Would have blocked ${data.watching.detected.toLocaleString("en")} ${data.watching.detected === 1 ? "request" : "requests"} in the last 7 days`}
                                actions={
                                    data.canManage ? (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            disabled={saving}
                                            onClick={() =>
                                                void update(
                                                    { mode: "block" },
                                                    "Attacks are blocked now",
                                                )
                                            }
                                        >
                                            Turn on blocking
                                        </Button>
                                    ) : undefined
                                }
                            >
                                {[
                                    data.watching.categories.join(", "),
                                    data.watching.topPath
                                        ? `most on ${data.watching.topPath}`
                                        : null,
                                ]
                                    .filter(Boolean)
                                    .join(", ")}
                            </Callout>
                        )}

                    <SettingsCard title="Last 7 days">
                        <div className="flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-center">
                            <div className="flex gap-8">
                                <div className="flex flex-col">
                                    <span className="type-stat tabular-nums">
                                        {formatCompact(data.blocked)}
                                    </span>
                                    <span className="type-small text-muted-foreground">
                                        blocked
                                    </span>
                                </div>
                                {(data.detected > 0 || data.mode === "log") && (
                                    <div className="flex flex-col">
                                        <span className="type-stat tabular-nums">
                                            {formatCompact(data.detected)}
                                        </span>
                                        <span className="type-small text-muted-foreground">
                                            logged
                                        </span>
                                    </div>
                                )}
                            </div>
                            {data.blocked + data.detected > 0 && (
                                <Sparkline
                                    values={data.series}
                                    label="Matched requests per hour, last 7 days"
                                    height={36}
                                    area
                                    min={0}
                                    className="min-w-0 flex-1"
                                />
                            )}
                        </div>
                    </SettingsCard>

                    <Exceptions data={data} onChanged={refresh} />

                    <SettingsCard title="Advanced">
                        <SettingsRow
                            label="Stricter rules"
                            description="More rules run, and some legitimate requests may be blocked."
                        >
                            <Select
                                value={String(data.paranoia)}
                                disabled={!data.canManage || saving}
                                onValueChange={(value) =>
                                    void update(
                                        { paranoia: Number(value) },
                                        Number(value) === 2
                                            ? "Stricter rules are on"
                                            : "Default rules are on",
                                    )
                                }
                            >
                                <SelectTrigger
                                    className="w-44"
                                    aria-label="Rule level"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="1">Default</SelectItem>
                                    <SelectItem value="2">Stricter</SelectItem>
                                </SelectContent>
                            </Select>
                        </SettingsRow>
                        <SettingsRow
                            label="Inspect request bodies up to"
                            description="Larger limits check more of each request but cost more server time: one request can take up to about 0.1 s of CPU at 32 KiB, 0.35 s at 128 KiB and 3 s at 1 MiB. Anything past the limit, and any form field past the 256th, is passed on unchecked."
                        >
                            <Select
                                value={String(data.inspectBodyKiB)}
                                disabled={!data.canManage || saving}
                                onValueChange={(value) =>
                                    void update(
                                        { inspectBodyKiB: Number(value) },
                                        `Request bodies are checked up to ${BODY_LABEL[value] ?? value}`,
                                    )
                                }
                            >
                                <SelectTrigger
                                    className="w-44"
                                    aria-label="Request body limit"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {Object.entries(BODY_LABEL).map(
                                        ([value, label]) => (
                                            <SelectItem
                                                key={value}
                                                value={value}
                                            >
                                                {label}
                                            </SelectItem>
                                        ),
                                    )}
                                </SelectContent>
                            </Select>
                        </SettingsRow>
                    </SettingsCard>

                    {data.ruleSet && (
                        <p className="type-small text-faint">{data.ruleSet}</p>
                    )}
                </>
            )}
        </SettingsPage>
    );
}

function Exceptions({
    data,
    onChanged,
}: {
    data: Firewall;
    onChanged: () => void;
}) {
    const [open, setOpen] = useState(false);

    const remove = async (id: string) => {
        await requestJson("delete", `${data.exclusionsUrl}/${id}`);
        toast.success("Exception removed");
        onChanged();
    };

    return (
        <SettingsCard
            title="Exceptions"
            description="Rules skipped for requests the firewall blocked by mistake."
            actions={
                data.canManage ? (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setOpen(true)}
                    >
                        <Plus aria-hidden="true" />
                        Add exception
                    </Button>
                ) : undefined
            }
        >
            {data.exclusions.length === 0 ? (
                <p className="px-5 py-4 type-small text-muted-foreground">
                    No exceptions. Open a blocked request on the Security page
                    to allow requests like it.
                </p>
            ) : (
                <ul className="divide-y">
                    {data.exclusions.map((x) => (
                        <li
                            key={x.id}
                            className="flex items-start gap-3 px-5 py-3"
                        >
                            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                                <span className="type-body">
                                    <code className="font-mono text-muted-foreground">
                                        {x.rule}
                                    </code>{" "}
                                    {x.message}
                                </span>
                                <span className="type-small [overflow-wrap:anywhere] text-muted-foreground">
                                    {x.target ? (
                                        <code className="font-mono">
                                            {x.target}
                                        </code>
                                    ) : (
                                        "Whole request"
                                    )}
                                    {x.path ? (
                                        <>
                                            {" under "}
                                            <code className="font-mono">
                                                {x.path}
                                            </code>
                                        </>
                                    ) : (
                                        " on every path"
                                    )}
                                    {x.addedBy
                                        ? `, added by ${x.addedBy} `
                                        : ", added "}
                                    <TimeAgo value={x.addedAt} />
                                </span>
                                {x.note && (
                                    <span className="type-small text-faint">
                                        {x.note}
                                    </span>
                                )}
                            </div>
                            {data.canManage && (
                                <ConfirmPopover
                                    consequence="The rule checks these requests again."
                                    confirmLabel="Remove"
                                    tone="destructive"
                                    onConfirm={() => remove(x.id)}
                                >
                                    <Button
                                        size="icon-sm"
                                        variant="ghost"
                                        aria-label={`Remove exception for rule ${x.rule}`}
                                    >
                                        <Trash2 aria-hidden="true" />
                                    </Button>
                                </ConfirmPopover>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            <AddException
                open={open}
                onOpenChange={setOpen}
                url={data.exclusionsUrl}
                onAdded={onChanged}
            />
        </SettingsCard>
    );
}

function AddException({
    open,
    onOpenChange,
    url,
    onAdded,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    url: string;
    onAdded: () => void;
}) {
    const [rule, setRule] = useState("");
    const [path, setPath] = useState("");
    const [target, setTarget] = useState("");
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const submit = async () => {
        setBusy(true);
        setError(null);

        try {
            await requestJson("post", url, {
                rule: Number(rule),
                path: path.trim() || null,
                target: target.trim() || null,
            });
            toast.success("Exception added");
            onOpenChange(false);
            setRule("");
            setPath("");
            setTarget("");
            onAdded();
        } catch (failure) {
            setError((failure as Error).message);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogTitle>Add exception</DialogTitle>
                <DialogDescription>
                    The rule is skipped for matching requests to this service.
                </DialogDescription>
                <div className="flex flex-col gap-4">
                    <label className="flex flex-col gap-1.5">
                        <span className="type-label">Rule</span>
                        <Input
                            value={rule}
                            inputMode="numeric"
                            placeholder="942100"
                            className="font-mono"
                            onChange={(e) => setRule(e.target.value)}
                        />
                        <span className="type-small text-muted-foreground">
                            The id on the blocked request, between 911000 and
                            944999.
                        </span>
                    </label>
                    <label className="flex flex-col gap-1.5">
                        <span className="type-label">Path (optional)</span>
                        <Input
                            value={path}
                            placeholder="/api/search"
                            className="font-mono"
                            onChange={(e) => setPath(e.target.value)}
                        />
                    </label>
                    <label className="flex flex-col gap-1.5">
                        <span className="type-label">
                            Part of the request (optional)
                        </span>
                        <Input
                            value={target}
                            placeholder="ARGS:q"
                            className="font-mono"
                            onChange={(e) => setTarget(e.target.value)}
                        />
                    </label>
                    {error && (
                        <p className="type-small text-status-error">{error}</p>
                    )}
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="ghost">Cancel</Button>
                    </DialogClose>
                    <Button
                        disabled={busy || rule === ""}
                        onClick={() => void submit()}
                    >
                        Add exception
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
