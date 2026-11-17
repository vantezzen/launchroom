// GET /events: server-sent events, one every second (?count=, default 5;
// ?interval= in ms). POST /upload: reads the body as a stream and answers
// with its size and SHA-256. GET /health: ok.
const http = require('node:http');
const crypto = require('node:crypto');

const server = http.createServer((req, res) => {
  const url = new URL(req.url, 'http://localhost');

  if (url.pathname === '/events') {
    const count = Math.min(Number(url.searchParams.get('count') || 5), 10000);
    const interval = Math.max(Number(url.searchParams.get('interval') || 1000), 10);
    res.writeHead(200, {
      'content-type': 'text/event-stream',
      'cache-control': 'no-cache',
      connection: 'keep-alive',
    });
    let sent = 0;
    const tick = () => {
      sent += 1;
      res.write(`id: ${sent}\nevent: tick\ndata: ${JSON.stringify({ n: sent, at: Date.now() })}\n\n`);
      if (sent >= count) {
        res.end();
        return;
      }
      timer = setTimeout(tick, interval);
    };
    let timer = setTimeout(tick, 0);
    req.on('close', () => clearTimeout(timer));
    return;
  }

  if (url.pathname === '/upload' && req.method === 'POST') {
    const hash = crypto.createHash('sha256');
    let bytes = 0;
    req.on('data', (chunk) => {
      bytes += chunk.length;
      hash.update(chunk);
    });
    req.on('end', () => {
      res.writeHead(200, { 'content-type': 'application/json' });
      res.end(JSON.stringify({ bytes, sha256: hash.digest('hex') }));
    });
    req.on('error', () => res.destroy());
    return;
  }

  res.writeHead(200, { 'content-type': 'text/plain' });
  res.end('ok\n');
});

server.requestTimeout = 0;
server.listen(Number(process.env.PORT || 3000), '0.0.0.0');
process.on('SIGTERM', () => server.close(() => process.exit(0)));
