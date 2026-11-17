// Writes to /data, a path the image doesn't have: a volume mounted there is
// the only way it exists, and the process runs as uid 1000.
const http = require('node:http');
const fs = require('node:fs');

const file = '/data/visits.txt';

http.createServer((req, res) => {
  if (req.url === '/write') {
    try {
      fs.appendFileSync(file, `${new Date().toISOString()}\n`);
      const visits = fs.readFileSync(file, 'utf8').trim().split('\n').length;
      res.writeHead(200, { 'content-type': 'application/json' });
      res.end(JSON.stringify({ ok: true, visits }));
    } catch (err) {
      res.writeHead(500, { 'content-type': 'application/json' });
      res.end(JSON.stringify({ ok: false, error: err.code || String(err) }));
    }
    return;
  }
  res.writeHead(200, { 'content-type': 'text/plain' });
  res.end('ok\n');
}).listen(Number(process.env.PORT || 3000), '0.0.0.0');
