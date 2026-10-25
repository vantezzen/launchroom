// launchroom dev fixture: a tiny server that loads lodash at runtime, so the
// security center sees a vulnerable runtime dependency (docs/22 §3.3).
import { createServer } from 'node:http';
import lodash from 'lodash';

const port = Number(process.env.PORT ?? 3000);

const server = createServer((req, res) => {
    if (req.url === '/health') {
        res.writeHead(200, { 'content-type': 'text/plain' });
        res.end('ok');
        return;
    }
    res.writeHead(200, { 'content-type': 'application/json' });
    res.end(JSON.stringify({ hello: lodash.capitalize('world'), path: req.url, method: req.method }));
});

server.listen(port, () => console.log(`node-vulnerable listening on ${port}`));
process.on('SIGTERM', () => server.close(() => process.exit(0)));
