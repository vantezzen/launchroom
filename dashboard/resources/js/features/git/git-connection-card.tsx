import { Link, router } from "@inertiajs/react";
import { RefreshCw } from "lucide-react";
import { useState } from "react";
import { CopyValue } from "@/components/copy-value";
import { MetaLine } from "@/components/patterns/meta-line";
import { Logo } from "@/components/logo";
import { Status } from "@/components/status";
import type { StatusState } from "@/components/status";
import { TimeAgo } from "@/components/time-ago";
import { Button } from "@/components/ui/button";
import { providerLogo } from "@/features/git/providers";
import { edit } from "@/routes/git";
import { requestJson, RequestFailed } from "@/features/projects/http";
import { recheck, webhook } from "@/routes/git/servers";

function health(connection: App.Data.Git.GitConnectionData): {
    state: StatusState;
    label: string;
} {
    switch (connection.status) {
        case "needs_reauth":
            return { state: "warning", label: "Needs you to sign in again" };
        case "pending":
            return {
                state: "idle",
                label:
                    connection.provider === "git"
                        ? "Waiting for you to check the host key and add the deploy key"
                        : "Not finished: sign in to connect",
            };
        default:
            if (
                connection.provider === "git" &&
                !connection.hostKeysConfirmed
            ) {
                return {
                    state: "warning",
                    label: "Host key changed: builds wait for you",
                };
            }

            if (connection.webhooksHealthy) {
                return { state: "active", label: "Pushes arrive right away" };
            }

            return connection.lastWebhookAt !== null
                ? {
                      state: "warning",
                      label: "A push didn’t arrive by webhook: checking every minute",
                  }
                : { state: "idle", label: "Checking for pushes every minute" };
    }
}

/**
 * One GitLab, Gitea/Forgejo or plain git connection (docs/19 §4): logo,
 * name, where it is, one health line and the one action that matters now.
 */
export function GitConnectionCard({
    connection,
    onSignInAgain,
}: {
    connection: App.Data.Git.GitConnectionData;
    onSignInAgain: (connection: App.Data.Git.GitConnectionData) => void;
}) {
    const line = health(connection);
    const plain = connection.provider === "git";
    const ssh = plain && connection.url.startsWith("ssh://");
    const repos = `${connection.repositoriesCount} ${connection.repositoriesCount === 1 ? "repository" : "repositories"}`;

    return (
        <section
            aria-labelledby={connection.id}
            className="overflow-hidden rounded-xl border bg-card shadow-card"
        >
            <div className="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex min-w-0 items-center gap-4">
                    <Logo
                        kind={providerLogo(connection.serverKind)}
                        size={44}
                        decorative
                    />
                    <div className="flex min-w-0 flex-col gap-1">
                        <h3
                            id={connection.id}
                            className="truncate type-heading"
                        >
                            {plain ? connection.name : connection.serverLabel}
                        </h3>
                        <p className="truncate type-small text-muted-foreground">
                            {plain ? (
                                <span className="type-mono-small">
                                    {connection.url}
                                </span>
                            ) : (
                                <MetaLine>
                                    {connection.host}
                                    {connection.accountLogin !== null &&
                                        `Signed in as ${connection.accountLogin}`}
                                    {connection.status === "active" &&
                                        `${repos} seen`}
                                </MetaLine>
                            )}
                        </p>
                    </div>
                </div>
                {connection.canManage && (
                    <div className="flex shrink-0 items-center gap-2">
                        {(connection.status === "needs_reauth" ||
                            (connection.status === "pending" && !plain)) && (
                            <Button
                                size="sm"
                                onClick={() => onSignInAgain(connection)}
                            >
                                {connection.status === "pending"
                                    ? "Continue"
                                    : "Sign in again"}
                            </Button>
                        )}
                        {plain &&
                            (connection.status === "pending" ||
                                !connection.hostKeysConfirmed) && (
                                <Button size="sm" asChild>
                                    <Link
                                        href={
                                            edit({
                                                query: { setup: connection.id },
                                            }).url
                                        }
                                        preserveScroll
                                    >
                                        Continue setup
                                    </Link>
                                </Button>
                            )}
                        {ssh &&
                            connection.status === "active" &&
                            connection.hostKeysConfirmed && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        router.post(
                                            recheck(connection.id).url,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <RefreshCw aria-hidden="true" />
                                    Check host key again
                                </Button>
                            )}
                    </div>
                )}
            </div>
            <div className="flex flex-col gap-0.5 border-t bg-recessed px-5 py-3">
                <Status state={line.state} label={line.label} />
                {connection.status === "active" &&
                    connection.lastWebhookAt !== null && (
                        <p className="pl-5 type-small text-muted-foreground">
                            Last delivery{" "}
                            <TimeAgo value={connection.lastWebhookAt} />
                        </p>
                    )}
                {connection.status !== "active" &&
                    connection.statusReason !== null && (
                        <p className="pl-5 type-small text-muted-foreground">
                            {connection.statusReason}
                        </p>
                    )}
            </div>
            {connection.provider === "git" &&
                connection.status === "active" &&
                connection.canManage && <PollWebhook id={connection.id} />}
        </section>
    );
}

type WebhookDetails = { url: string; header: string; secret: string };

/**
 * The optional "poll now" webhook of a plain git server (docs/19 §4.2): the
 * secret is fetched on request (audited), never part of the page.
 */
function PollWebhook({ id }: { id: string }) {
    const [details, setDetails] = useState<WebhookDetails | null>(null);
    const [error, setError] = useState<string | null>(null);

    async function reveal() {
        try {
            setError(null);
            setDetails(
                await requestJson<WebhookDetails>("post", webhook(id).url),
            );
        } catch (e) {
            setError(e instanceof RequestFailed ? e.message : "Try again.");
        }
    }

    if (details === null) {
        return (
            <div className="flex flex-wrap items-center gap-3 border-t px-5 py-3">
                <p className="type-small text-muted-foreground">
                    Let your git server notify launchroom after each push
                    instead of waiting for the next check.
                </p>
                <Button variant="ghost" size="sm" onClick={reveal}>
                    Set up a webhook
                </Button>
                {error !== null && (
                    <p className="type-small text-destructive">{error}</p>
                )}
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-2 border-t px-5 py-4">
            <p className="type-small text-muted-foreground">
                POST anything to this URL with{" "}
                <code className="font-mono">
                    {details.header}: sha256=&lt;HMAC-SHA256 of the body&gt;
                </code>{" "}
                (<code className="font-mono">X-Hub-Signature-256</code> works
                too). launchroom then checks the branches right away.
            </p>
            <CopyValue value={details.url} label="webhook URL" />
            <CopyValue value={details.secret} label="webhook secret" />
        </div>
    );
}
