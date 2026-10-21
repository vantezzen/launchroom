import Image from 'next/image';
import { ArrowLeft, Plus } from 'lucide-react';
import { LogoMark } from '@/components/logo';

/**
 * The default setup: launchroom on one VPS, a machine at home that builds.
 * The home machine connects out to the VPS; both share a private network.
 * A dashed slot shows that more servers can join later.
 */
export function ArchitectureDiagram() {
    return (
        <figure className="m-0">
            <figcaption className="sr-only">
                launchroom runs on your VPS. A machine at home connects out to it and becomes the build server. Both
                share a private network, so nothing has to be opened on the home router. More servers can join the same
                way.
            </figcaption>
            <div
                className="grid items-center gap-3 md:grid-cols-[minmax(0,1fr)_120px_minmax(0,1fr)]"
                aria-hidden="true"
            >
                <div className="flex flex-col overflow-hidden rounded-card border border-border-strong bg-card">
                    <div className="flex h-[112px] items-end justify-center bg-recessed px-6 pt-4">
                        <Image
                            src="/devices/server.webp"
                            width={320}
                            height={200}
                            alt=""
                            sizes="200px"
                            className="h-[100px] w-auto"
                        />
                    </div>
                    <div className="flex flex-col gap-2 p-4">
                        <div className="flex items-center gap-2">
                            <span className="size-2 rounded-full bg-status-ok" />
                            <span className="font-mono text-[13px] font-medium">vps-fra-1</span>
                            <span className="type-small text-muted-foreground">your VPS</span>
                        </div>
                        <div className="flex items-center gap-2 rounded-[10px] border border-border bg-recessed px-3 py-2">
                            <LogoMark className="size-4" />
                            <span className="text-[13px]">launchroom dashboard</span>
                        </div>
                        <p className="type-small text-muted-foreground">Serves your apps and databases</p>
                    </div>
                </div>

                <div className="flex flex-row items-center justify-center gap-2 py-1 md:flex-col md:py-0">
                    <ArrowLeft className="size-4 rotate-90 text-muted-foreground md:rotate-0" />
                    <span className="text-center type-small text-muted-foreground">connects out</span>
                    <span className="hidden h-px w-full border-t border-dashed border-signal md:block" />
                    <span className="text-center font-mono text-[11px] text-signal">private network</span>
                </div>

                <div className="flex flex-col overflow-hidden rounded-card border border-border-strong bg-card">
                    <div className="flex h-[112px] items-end justify-center bg-recessed px-6 pt-4">
                        <Image
                            src="/devices/tower.webp"
                            width={320}
                            height={200}
                            alt=""
                            sizes="200px"
                            className="h-[100px] w-auto"
                        />
                    </div>
                    <div className="flex flex-col gap-2 p-4">
                        <div className="flex items-center gap-2">
                            <span className="size-2 rounded-full bg-status-ok" />
                            <span className="font-mono text-[13px] font-medium">home-desktop</span>
                            <span className="type-small text-muted-foreground">at home</span>
                        </div>
                        <div className="rounded-[10px] border border-border bg-recessed px-3 py-2 text-[13px]">
                            Builds every push
                        </div>
                        <p className="type-small text-muted-foreground">Behind your router, no ports opened</p>
                    </div>
                </div>
            </div>
            <div
                className="mt-3 flex items-center justify-center gap-2 rounded-card border border-dashed border-border-strong px-4 py-3 text-[13px] text-muted-foreground"
                aria-hidden="true"
            >
                <Plus className="size-3.5" />
                More servers join the same way, whenever you need them
            </div>
        </figure>
    );
}
