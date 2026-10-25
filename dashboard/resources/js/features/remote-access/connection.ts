import type { Access } from './api';

/** A host for a URL or command: IPv6 in brackets. */
function hostPart(host: string): string {
    return host.includes(':') ? `[${host}]` : host;
}

/** File name the certificate downloads as (sslrootcert / --cacert). */
export const CERT_FILE = 'launchroom-access.crt';

/** One ready-made command or config block for a client. */
export type Snippet = {
    /** Tab label ("ssh", "~/.ssh/config"). */
    id: string;
    label: string;
    value: string;
    /** One line under the block. */
    hint: string;
    /** Holds the password: shown masked until revealed. */
    secret?: boolean;
};

/** A short, safe `Host` alias for ~/.ssh/config ("launchroom-api-files"). */
export function hostAlias(name: string, suffix?: string): string {
    const slug = name
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 40);

    return ['launchroom', slug || 'service', suffix].filter(Boolean).join('-');
}

/**
 * The snippets for an access: commands for the common clients and, for
 * SSH and volumes, a `~/.ssh/config` block. The password appears only in
 * snippets marked secret.
 */
export function snippets(
    access: Access,
    host: string,
    serviceName: string,
): Snippet[] {
    const port = access.port ?? 0;
    const user = access.username;
    const h = hostPart(host);
    const password = access.password ?? '';
    const withKey = access.authMethod === 'key';
    const askHint = withKey
        ? 'Signs in with your key.'
        : 'Paste the password when your client asks for it.';

    if (access.kind === 'ssh' || access.kind === 'volume') {
        const volume = access.kind === 'volume';
        const alias = hostAlias(serviceName, volume ? 'files' : undefined);
        const target = volume ? '/' : '/tmp/';
        const list: Snippet[] = [];

        if (!volume) {
            list.push({
                id: 'ssh',
                label: 'ssh',
                value: `ssh -p ${port} ${user}@${host}`,
                hint: `A shell in the container. ${askHint}`,
            });
        }

        list.push(
            {
                id: 'sftp',
                label: 'sftp',
                value: `sftp -P ${port} ${user}@${host}`,
                hint: volume
                    ? `Opens at the top folder of the volume. ${askHint}`
                    : `Browse and transfer files. ${askHint}`,
            },
            {
                id: 'scp',
                label: 'scp',
                value: `scp -P ${port} ./file.txt ${user}@${h}:${target}`,
                hint: volume
                    ? 'Copies a file into the top folder of the volume.'
                    : 'Copies a file into /tmp in the container.',
            },
            {
                id: 'config',
                label: '~/.ssh/config',
                value: [
                    `Host ${alias}`,
                    `  HostName ${host}`,
                    `  Port ${port}`,
                    `  User ${user}`,
                    ...(withKey ? ['  IdentitiesOnly yes'] : []),
                ].join('\n'),
                hint: `Add it to ~/.ssh/config, then run ${volume ? 'sftp' : 'ssh'} ${alias}. Remove it when the access ends.`,
            },
        );

        return list;
    }

    if (access.engine === 'postgres') {
        const db = access.database ?? 'postgres';

        return [
            {
                id: 'url',
                label: 'Connection URL',
                value: `postgresql://${user}:${encodeURIComponent(password)}@${h}:${port}/${db}?sslmode=require`,
                hint: 'For TablePlus, DBeaver and most libraries.',
                secret: true,
            },
            {
                id: 'psql',
                label: 'psql',
                value: `psql "host=${host} port=${port} dbname=${db} user=${user} sslmode=verify-full sslrootcert=${CERT_FILE}"`,
                hint: 'Verifies the server with the downloaded certificate.',
            },
        ];
    }

    const cli = access.engine === 'valkey' ? 'valkey-cli' : 'redis-cli';

    return [
        {
            id: 'url',
            label: 'Connection URL',
            value: `rediss://${user}:${encodeURIComponent(password)}@${h}:${port}`,
            hint: 'For RedisInsight and most libraries.',
            secret: true,
        },
        {
            id: 'cli',
            label: cli,
            value: `${cli} --tls --cacert ${CERT_FILE} -h ${host} -p ${port} --user ${user} --askpass`,
            hint: 'Verifies the server with the downloaded certificate.',
        },
    ];
}

/** "1:52:07" or "12:07". */
export function countdown(seconds: number): string {
    const s = Math.max(0, Math.floor(seconds));
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const sec = String(s % 60).padStart(2, '0');

    return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${sec}` : `${m}:${sec}`;
}

/** "SHA256 1a2b…" grouped in fours for reading aloud. */
export function groupHex(hex: string): string {
    return (hex.match(/.{1,4}/g) ?? []).join(' ');
}

/** "30 minutes", "1 hour", "2 hours". */
export function durationLabel(minutes: number): string {
    if (minutes < 60) {
        return `${minutes} minutes`;
    }

    const hours = minutes / 60;

    return `${hours} ${hours === 1 ? 'hour' : 'hours'}`;
}
