import { Form, Link } from '@inertiajs/react';
import { Gauge } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { LearnMore, useDocsUrl } from '@/features/settings/settings-card';
import { create as addServer } from '@/routes/servers';
import { dismiss } from '@/routes/servers/small-server-hint';

function size(hint: App.Data.Servers.SmallServerHintData): string {
    const cpus = `${hint.cpus} ${hint.cpus === 1 ? 'CPU core' : 'CPU cores'}`;

    if (hint.memoryMb <= 0) {
        return cpus;
    }

    const gb = hint.memoryMb / 1024;

    return `${cpus}, ${gb >= 10 ? Math.round(gb) : gb.toFixed(1).replace(/\.0$/, '')} GB memory`;
}

/**
 * "This server is small" (docs/08 §14): the team's only build server has
 * little CPU or memory, so a build can slow the apps running next to it.
 * Calm and dismissible (per user); hidden once another build server exists.
 */
export function SmallServerHint({
    hint,
}: {
    hint: App.Data.Servers.SmallServerHintData;
}) {
    const docsUrl = useDocsUrl();

    return (
        <section
            aria-labelledby="small-server-hint"
            className="flex flex-col gap-4 rounded-xl border bg-card p-5 shadow-card sm:flex-row sm:items-start"
        >
            <span className="flex size-10 shrink-0 items-center justify-center rounded-lg border bg-background text-muted-foreground">
                <Gauge aria-hidden="true" className="size-5" />
            </span>
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <h2 id="small-server-hint" className="type-label">
                    {hint.serverName} is small ({size(hint)})
                </h2>
                <p className="max-w-[65ch] type-small text-muted-foreground">
                    A heavy build next to your running apps can slow them down
                    or briefly make them unavailable while the build takes the
                    CPU and memory. Add a separate build server, even your home
                    server, a Raspberry Pi or your laptop, to keep your apps at
                    full speed.
                </p>
                <div className="flex flex-wrap gap-x-4 gap-y-1 pt-1">
                    <LearnMore
                        className="type-small"
                        href={docsUrl('servers/home-machines')}
                    >
                        Build on a home machine
                    </LearnMore>
                    <LearnMore
                        className="type-small"
                        href={docsUrl('servers/add-a-server')}
                    >
                        Add a server
                    </LearnMore>
                </div>
            </div>
            <div className="flex shrink-0 items-center gap-2">
                <Form {...dismiss.form()} options={{ preserveScroll: true }}>
                    {({ processing }) => (
                        <Button
                            type="submit"
                            variant="ghost"
                            size="sm"
                            disabled={processing}
                        >
                            Dismiss
                        </Button>
                    )}
                </Form>
                <Button asChild variant="outline" size="sm">
                    <Link href={addServer()}>Add a build server</Link>
                </Button>
            </div>
        </section>
    );
}
