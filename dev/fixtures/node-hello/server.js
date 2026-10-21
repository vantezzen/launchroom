import { readFileSync } from 'node:fs';
import { createServer } from 'node:http';
import { hostname } from 'node:os';
import { query } from './db.js';

// launchroom injects PORT; default to 3000 for running it by hand.
const port = Number.parseInt(process.env.PORT ?? '3000', 10);

// Branch overlays (dev/fixtures/README.md "Branches") may add release.txt,
// so a redeploy of a new commit is visible in the responses.
let release = null;
try {
    release = readFileSync(new URL('./release.txt', import.meta.url), 'utf8').trim();
} catch {
    // main has no release.txt
}

// The container's hostname (its short id): which replica answered. ($HOSTNAME
// is the bind address in launchroom's runtime env, not the host name.)
const replica = hostname();

const json = (res, status, body) => {
    res.writeHead(status, { 'content-type': 'application/json' });
    res.end(JSON.stringify(body) + '\n');
};

const server = createServer((req, res) => {
    if (req.url === '/health') {
        res.writeHead(200, { 'content-type': 'text/plain' });
        res.end('ok\n');
        return;
    }

    // One database round trip (DATABASE_URL from a connected Postgres).
    if (req.url === '/db') {
        const started = Date.now();
        if (!process.env.DATABASE_URL) {
            json(res, 503, { error: 'no DATABASE_URL', replica });
            return;
        }
        query(process.env.DATABASE_URL, 'SELECT inet_server_addr()::text, pg_backend_pid()::text')
            .then((rows) =>
                json(res, 200, {
                    replica,
                    release,
                    db: { server: rows[0]?.[0] ?? null, pid: rows[0]?.[1] ?? null, ms: Date.now() - started },
                }),
            )
            .catch((err) => json(res, 503, { error: String(err.message ?? err), replica }));
        return;
    }

    json(res, 200, {
        hello: 'from launchroom',
        release,
        replica,
        path: req.url,
    });
});

server.listen(port, '0.0.0.0', () => {
    console.log(`node-hello listening on :${port}`);
});

// Graceful shutdown so rolling deploys can drain connections.
for (const signal of ['SIGTERM', 'SIGINT']) {
    process.on(signal, () => server.close(() => process.exit(0)));
}
