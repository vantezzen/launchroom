import { createServer } from 'node:http';

// launchroom injects PORT; default to 3000 for running it by hand.
const port = Number.parseInt(process.env.PORT ?? '3000', 10);

// Preview branch of the fixture (dev/fixtures/README.md "Branches"): also
// reports which commit runs and which database it was given.
function databaseHost() {
    try {
        return new URL(process.env.DATABASE_URL ?? '').hostname || null;
    } catch {
        return null;
    }
}

const server = createServer((req, res) => {
    if (req.url === '/health') {
        res.writeHead(200, { 'content-type': 'text/plain' });
        res.end('ok\n');
        return;
    }

    res.writeHead(200, { 'content-type': 'application/json' });
    res.end(
        JSON.stringify({
            hello: 'from launchroom',
            preview: 'v1',
            commit: process.env.LAUNCHROOM_GIT_COMMIT_SHA ?? null,
            environment: process.env.LAUNCHROOM_ENVIRONMENT ?? null,
            database: databaseHost(),
            hostname: process.env.HOSTNAME ?? null,
            path: req.url,
        }) + '\n',
    );
});

server.listen(port, '0.0.0.0', () => {
    console.log(`node-hello (preview v1) listening on :${port}`);
});

// Graceful shutdown so rolling deploys can drain connections.
for (const signal of ['SIGTERM', 'SIGINT']) {
    process.on(signal, () => server.close(() => process.exit(0)));
}
