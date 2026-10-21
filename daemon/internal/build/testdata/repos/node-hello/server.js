import { createServer } from 'node:http';

// launchroom injects PORT; default to 3000 for running it by hand.
const port = Number.parseInt(process.env.PORT ?? '3000', 10);

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
            hostname: process.env.HOSTNAME ?? null,
            path: req.url,
        }) + '\n',
    );
});

server.listen(port, '0.0.0.0', () => {
    console.log(`node-hello listening on :${port}`);
});

// Graceful shutdown so rolling deploys can drain connections.
for (const signal of ['SIGTERM', 'SIGINT']) {
    process.on(signal, () => server.close(() => process.exit(0)));
}
