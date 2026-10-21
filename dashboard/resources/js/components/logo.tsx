import type { LucideIcon } from 'lucide-react';
import {
    Box,
    Clock,
    Cloud,
    Cog,
    DatabaseZap,
    Globe,
    HardDrive,
    Mail,
    Server,
    Webhook,
} from 'lucide-react';
import type { CSSProperties } from 'react';
import type { SimpleIcon } from 'simple-icons';
import {
    siCelery,
    siForgejo,
    siGandi,
    siGhost,
    siGitea,
    siGrafana,
    siMetabase,
    siN8n,
    siNetcup,
    siNextcloud,
    siPlausibleanalytics,
    siPocketbase,
    siPorkbun,
    siSidekiq,
    siUmami,
    siUptimekuma,
    siWordpress,
} from 'simple-icons';
import {
    siAkamai,
    siAstro,
    siBackblaze,
    siBun,
    siCaddy,
    siClickhouse,
    siCloudflare,
    siDebian,
    siDeno,
    siDigitalocean,
    siDiscord,
    siDjango,
    siDocker,
    siFastapi,
    siFlask,
    siGit,
    siGithub,
    siGitlab,
    siGo,
    siGooglecloud,
    siHetzner,
    siLaravel,
    siMariadb,
    siMeilisearch,
    siMinio,
    siMongodb,
    siMysql,
    siNextdotjs,
    siNginx,
    siNodedotjs,
    siNuxt,
    siOvh,
    siPhp,
    siPostgresql,
    siPython,
    siRabbitmq,
    siRaspberrypi,
    siRedis,
    siRemix,
    siRuby,
    siRubyonrails,
    siRust,
    siScaleway,
    siSlack,
    siSvelte,
    siTailscale,
    siUbuntu,
    siVite,
    siVultr,
} from 'simple-icons';
import { cn } from '@/lib/utils';

type Mark =
    | { icon: SimpleIcon; label: string }
    | { lucide: LucideIcon; label: string };

/**
 * Logo keys → marks. Covers today's glyph keys (App\Support\Canvas\
 * ServiceGlyph: frameworks, databases, `docker`, `github`, `static`,
 * `worker`, `cron`, `app`) plus providers and git hosts. Simple Icons data
 * is CC0; the marks stay their owners' trademarks and are used only to
 * identify the user's own stack (design-system §3.5).
 */
const marks: Record<string, Mark> = {
    // Frameworks and runtimes
    nextjs: { icon: siNextdotjs, label: 'Next.js' },
    laravel: { icon: siLaravel, label: 'Laravel' },
    node: { icon: siNodedotjs, label: 'Node.js' },
    python: { icon: siPython, label: 'Python' },
    django: { icon: siDjango, label: 'Django' },
    fastapi: { icon: siFastapi, label: 'FastAPI' },
    flask: { icon: siFlask, label: 'Flask' },
    vite: { icon: siVite, label: 'Vite' },
    astro: { icon: siAstro, label: 'Astro' },
    nuxt: { icon: siNuxt, label: 'Nuxt' },
    remix: { icon: siRemix, label: 'Remix' },
    svelte: { icon: siSvelte, label: 'Svelte' },
    bun: { icon: siBun, label: 'Bun' },
    deno: { icon: siDeno, label: 'Deno' },
    php: { icon: siPhp, label: 'PHP' },
    ruby: { icon: siRuby, label: 'Ruby' },
    rails: { icon: siRubyonrails, label: 'Rails' },
    go: { icon: siGo, label: 'Go' },
    rust: { icon: siRust, label: 'Rust' },
    nginx: { icon: siNginx, label: 'nginx' },
    caddy: { icon: siCaddy, label: 'Caddy' },
    // Databases and services
    postgres: { icon: siPostgresql, label: 'PostgreSQL' },
    mysql: { icon: siMysql, label: 'MySQL' },
    mariadb: { icon: siMariadb, label: 'MariaDB' },
    redis: { icon: siRedis, label: 'Redis' },
    valkey: { lucide: DatabaseZap, label: 'Valkey' },
    mongodb: { icon: siMongodb, label: 'MongoDB' },
    clickhouse: { icon: siClickhouse, label: 'ClickHouse' },
    rabbitmq: { icon: siRabbitmq, label: 'RabbitMQ' },
    meilisearch: { icon: siMeilisearch, label: 'Meilisearch' },
    minio: { icon: siMinio, label: 'MinIO' },
    // One-click apps (templates, docs/17 §3.2)
    umami: { icon: siUmami, label: 'Umami' },
    plausible: { icon: siPlausibleanalytics, label: 'Plausible' },
    uptimekuma: { icon: siUptimekuma, label: 'Uptime Kuma' },
    n8n: { icon: siN8n, label: 'n8n' },
    ghost: { icon: siGhost, label: 'Ghost' },
    gitea: { icon: siGitea, label: 'Gitea' },
    forgejo: { icon: siForgejo, label: 'Forgejo' },
    pocketbase: { icon: siPocketbase, label: 'PocketBase' },
    metabase: { icon: siMetabase, label: 'Metabase' },
    wordpress: { icon: siWordpress, label: 'WordPress' },
    grafana: { icon: siGrafana, label: 'Grafana' },
    nextcloud: { icon: siNextcloud, label: 'Nextcloud' },
    celery: { icon: siCelery, label: 'Celery' },
    sidekiq: { icon: siSidekiq, label: 'Sidekiq' },
    // Sources
    docker: { icon: siDocker, label: 'Image' },
    github: { icon: siGithub, label: 'GitHub repository' },
    gitlab: { icon: siGitlab, label: 'GitLab' },
    git: { icon: siGit, label: 'Git server' },
    // Providers and operating systems
    hetzner: { icon: siHetzner, label: 'Hetzner' },
    digitalocean: { icon: siDigitalocean, label: 'DigitalOcean' },
    googlecloud: { icon: siGooglecloud, label: 'Google Cloud' },
    vultr: { icon: siVultr, label: 'Vultr' },
    ovh: { icon: siOvh, label: 'OVHcloud' },
    scaleway: { icon: siScaleway, label: 'Scaleway' },
    akamai: { icon: siAkamai, label: 'Akamai' },
    cloudflare: { icon: siCloudflare, label: 'Cloudflare' },
    // DNS providers (DNS-01, docs/05 §5; Route 53 and deSEC have no mark: monogram)
    porkbun: { icon: siPorkbun, label: 'Porkbun' },
    gandi: { icon: siGandi, label: 'Gandi' },
    netcup: { icon: siNetcup, label: 'netcup' },
    backblaze: { icon: siBackblaze, label: 'Backblaze' },
    raspberrypi: { icon: siRaspberrypi, label: 'Raspberry Pi' },
    ubuntu: { icon: siUbuntu, label: 'Ubuntu' },
    debian: { icon: siDebian, label: 'Debian' },
    tailscale: { icon: siTailscale, label: 'Tailscale' },
    // Notification channels (docs/15 §3.5)
    slack: { icon: siSlack, label: 'Slack' },
    discord: { icon: siDiscord, label: 'Discord' },
    email: { lucide: Mail, label: 'Email' },
    webhook: { lucide: Webhook, label: 'Webhook' },
    // Generic kinds (no brand)
    static: { lucide: Globe, label: 'Static site' },
    worker: { lucide: Cog, label: 'Worker' },
    cron: { lucide: Clock, label: 'Cron' },
    app: { lucide: Box, label: 'App' },
    server: { lucide: Server, label: 'Server' },
    cloud: { lucide: Cloud, label: 'Cloud' },
    s3: { lucide: HardDrive, label: 'S3-compatible storage' },
};

export type LogoSize = 20 | 32 | 44;

const tile: Record<LogoSize, { box: string; glyph: number; text: string }> = {
    20: { box: 'size-5 rounded-[5px]', glyph: 12, text: 'text-[9px]' },
    32: { box: 'size-8 rounded-[9px]', glyph: 18, text: 'text-[12px]' },
    44: { box: 'size-11 rounded-[11px]', glyph: 24, text: 'text-[15px]' },
};

/** Human label for a logo key ("nextjs" → "Next.js"). */
export function logoLabel(kind: string, fallback?: string): string {
    return marks[kind]?.label ?? fallback ?? kind;
}

export function hasLogo(kind: string): boolean {
    return kind in marks;
}

type LogoProps = {
    /** Logo key: a glyph key from the server or a provider/tool slug. */
    kind: string;
    size?: LogoSize;
    /** Name for the monogram fallback and the accessible label. */
    name?: string;
    /** Decorative next to a visible name: hide it from screen readers. */
    decorative?: boolean;
    className?: string;
    style?: CSSProperties;
};

/**
 * Framework / database / provider logo on a neutral tile (design-system
 * §3.5). Monochrome by decision (implementation plan §5.1): the mark takes
 * the foreground colour, never its brand colour. Unknown kinds fall back
 * to a monogram.
 */
export function Logo({
    kind,
    size = 32,
    name,
    decorative = false,
    className,
    style,
}: LogoProps) {
    const mark = marks[kind];
    const label = mark?.label ?? name ?? kind;
    const spec = tile[size];

    return (
        <span
            role={decorative ? undefined : 'img'}
            aria-label={decorative ? undefined : label}
            aria-hidden={decorative ? true : undefined}
            title={label}
            className={cn(
                'inline-flex shrink-0 items-center justify-center border bg-card text-foreground',
                spec.box,
                className,
            )}
            style={style}
        >
            {mark && 'icon' in mark ? (
                <svg
                    viewBox="0 0 24 24"
                    width={spec.glyph}
                    height={spec.glyph}
                    fill="currentColor"
                    aria-hidden="true"
                >
                    <path d={mark.icon.path} />
                </svg>
            ) : mark ? (
                <mark.lucide
                    aria-hidden="true"
                    style={{ width: spec.glyph, height: spec.glyph }}
                />
            ) : (
                <span
                    aria-hidden="true"
                    className={cn(
                        'leading-none font-semibold tracking-tight',
                        spec.text,
                    )}
                >
                    {monogram(name ?? kind)}
                </span>
            )}
        </span>
    );
}

function monogram(value: string): string {
    const words = value
        .replace(/[^a-zA-Z0-9 ]+/g, ' ')
        .trim()
        .split(/\s+/)
        .filter(Boolean);

    if (words.length === 0) {
        return '?';
    }

    if (words.length === 1) {
        return words[0].slice(0, 2).replace(/^./, (c) => c.toUpperCase());
    }

    return (words[0][0] + words[1][0]).toUpperCase();
}
