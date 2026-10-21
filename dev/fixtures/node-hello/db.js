// Minimal PostgreSQL client for the node-hello fixture (no dependencies, so
// the fixture stays a plain Railpack Node app): startup, cleartext / MD5 /
// SCRAM-SHA-256 authentication, one simple query, terminate. Enough for a
// round trip that proves the app reaches its database (the failover test
// queries it from replicas on several servers, through the ambassador).
import { createHash, createHmac, pbkdf2Sync, randomBytes } from 'node:crypto';
import { connect } from 'node:net';

function message(type, body) {
    const head = Buffer.alloc(type ? 5 : 4);
    if (type) head.write(type, 0, 'ascii');
    head.writeInt32BE(body.length + 4, type ? 1 : 0);
    return Buffer.concat([head, body]);
}

const cstr = (s) => Buffer.from(`${s}\0`, 'utf8');

function scram(password) {
    const nonce = randomBytes(18).toString('base64');
    const clientFirstBare = `n=,r=${nonce}`;
    return {
        first: `n,,${clientFirstBare}`,
        final(serverFirst) {
            const attrs = Object.fromEntries(serverFirst.split(',').map((p) => [p[0], p.slice(2)]));
            if (!attrs.r?.startsWith(nonce)) throw new Error('SCRAM: server nonce mismatch');
            const salted = pbkdf2Sync(password, Buffer.from(attrs.s, 'base64'), Number(attrs.i), 32, 'sha256');
            const clientKey = createHmac('sha256', salted).update('Client Key').digest();
            const storedKey = createHash('sha256').update(clientKey).digest();
            const withoutProof = `c=biws,r=${attrs.r}`;
            const auth = `${clientFirstBare},${serverFirst},${withoutProof}`;
            const signature = createHmac('sha256', storedKey).update(auth).digest();
            const proof = Buffer.from(clientKey.map((b, i) => b ^ signature[i]));
            return `${withoutProof},p=${proof.toString('base64')}`;
        },
    };
}

/** Runs one simple query; resolves with the rows (arrays of strings or null). */
export function query(url, sql, timeoutMs = 3000) {
    const u = new URL(url);
    const user = decodeURIComponent(u.username);
    const password = decodeURIComponent(u.password);
    const database = decodeURIComponent(u.pathname.slice(1)) || user;

    return new Promise((resolve, reject) => {
        const socket = connect({ host: u.hostname, port: Number(u.port || 5432) });
        const rows = [];
        let buffer = Buffer.alloc(0);
        let sasl = null;
        let error = null;
        const fail = (err) => {
            socket.destroy();
            reject(err);
        };
        socket.setTimeout(timeoutMs, () => fail(new Error('database timeout')));
        socket.on('error', fail);
        socket.on('connect', () => {
            const params = Buffer.concat([cstr('user'), cstr(user), cstr('database'), cstr(database), Buffer.from([0])]);
            const version = Buffer.alloc(4);
            version.writeInt32BE(196608);
            socket.write(message(null, Buffer.concat([version, params])));
        });
        socket.on('data', (chunk) => {
            buffer = Buffer.concat([buffer, chunk]);
            while (buffer.length >= 5) {
                const type = String.fromCharCode(buffer[0]);
                const len = buffer.readInt32BE(1);
                if (buffer.length < len + 1) return;
                const body = buffer.subarray(5, len + 1);
                buffer = buffer.subarray(len + 1);
                try {
                    if (type === 'R') {
                        const code = body.readInt32BE(0);
                        if (code === 3) socket.write(message('p', cstr(password)));
                        else if (code === 5) {
                            const inner = createHash('md5').update(password + user).digest('hex');
                            const outer = createHash('md5').update(Buffer.concat([Buffer.from(inner), body.subarray(4, 8)])).digest('hex');
                            socket.write(message('p', cstr(`md5${outer}`)));
                        } else if (code === 10) {
                            sasl = scram(password);
                            const first = Buffer.from(sasl.first);
                            const len32 = Buffer.alloc(4);
                            len32.writeInt32BE(first.length);
                            socket.write(message('p', Buffer.concat([cstr('SCRAM-SHA-256'), len32, first])));
                        } else if (code === 11) {
                            socket.write(message('p', Buffer.from(sasl.final(body.subarray(4).toString()))));
                        } else if (code !== 0 && code !== 12) {
                            throw new Error(`unsupported authentication method ${code}`);
                        }
                    } else if (type === 'Z') {
                        if (sasl !== 'done') {
                            sasl = 'done';
                            socket.write(message('Q', cstr(sql)));
                        } else {
                            socket.end(message('X', Buffer.alloc(0)));
                            if (error) reject(error);
                            else resolve(rows);
                        }
                    } else if (type === 'D') {
                        const row = [];
                        let off = 2;
                        for (let i = 0; i < body.readInt16BE(0); i++) {
                            const n = body.readInt32BE(off);
                            off += 4;
                            row.push(n < 0 ? null : body.subarray(off, off + n).toString());
                            off += Math.max(n, 0);
                        }
                        rows.push(row);
                    } else if (type === 'E') {
                        const fields = body.toString().split('\0');
                        error = new Error(fields.find((f) => f.startsWith('M'))?.slice(1) ?? 'database error');
                        if (sasl !== 'done') fail(error);
                    }
                } catch (err) {
                    fail(err);
                }
            }
        });
    });
}
