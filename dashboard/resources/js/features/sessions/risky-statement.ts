/**
 * Statements worth a second look before they run with writes allowed
 * (docs/08 §15 Query). Advisory only: launchroomd's own policy is the real
 * guard, this just catches the "forgot the WHERE" moment.
 */

export type Dialect = App.Data.Sessions.DataTabData['dialect'];

/** What the statement would do, as a sentence ("This deletes every row in orders."). */
export type Risk = { consequence: string };

/** SQL without comments and with string literals blanked, so keywords inside them don't count. */
function stripSql(sql: string): string {
    return sql
        .replace(/--[^\n]*/g, ' ')
        .replace(/\/\*[\s\S]*?\*\//g, ' ')
        .replace(/'(?:[^']|'')*'/g, "''")
        .replace(/\$\$[\s\S]*?\$\$/g, "''");
}

function name(raw: string): string {
    return raw.replace(/[";`]/g, '').replace(/^.*\./, '');
}

function sqlRisk(statement: string): Risk | null {
    const s = statement.trim().replace(/\s+/g, ' ');
    const hasWhere = /\bwhere\b/i.test(s);
    let m: RegExpMatchArray | null;

    if ((m = s.match(/^delete from (?:only )?(\S+)/i)) && !hasWhere) {
        return { consequence: `This deletes every row in ${name(m[1])}.` };
    }

    if ((m = s.match(/^update (?:only )?(\S+) set\b/i)) && !hasWhere) {
        return { consequence: `This changes every row in ${name(m[1])}.` };
    }

    if ((m = s.match(/^truncate (?:table )?(?:only )?(\S+)/i))) {
        return { consequence: `This empties ${name(m[1])}.` };
    }

    if (
        (m = s.match(
            /^drop (table|schema|database|view|materialized view|index|sequence|type|function|extension) (?:if exists )?(\S+)/i,
        ))
    ) {
        return {
            consequence: `This drops the ${m[1].toLowerCase()} ${name(m[2])}.`,
        };
    }

    if (
        (m = s.match(/^alter table (?:if exists )?(?:only )?(\S+) .*\bdrop\b/i))
    ) {
        return {
            consequence: `This drops part of ${name(m[1])}, with the data in it.`,
        };
    }

    return null;
}

function redisRisk(statement: string): Risk | null {
    const command = statement.trim().split(/\s+/)[0]?.toUpperCase();

    if (command === 'FLUSHALL' || command === 'FLUSHDB') {
        return { consequence: 'This deletes every key.' };
    }

    return null;
}

function mongoRisk(statement: string): Risk | null {
    let doc: unknown;

    try {
        doc = JSON.parse(statement);
    } catch {
        return null;
    }

    if (typeof doc !== 'object' || doc === null) {
        return null;
    }

    const cmd = doc as Record<string, unknown>;

    if ('dropDatabase' in cmd) {
        return { consequence: 'This drops the whole database.' };
    }

    if (typeof cmd.drop === 'string') {
        return { consequence: `This drops the collection ${cmd.drop}.` };
    }

    if (typeof cmd.delete === 'string' && Array.isArray(cmd.deletes)) {
        const all = cmd.deletes.some(
            (d) =>
                typeof d === 'object' &&
                d !== null &&
                JSON.stringify((d as { q?: unknown }).q ?? {}) === '{}',
        );

        if (all) {
            return {
                consequence: `This deletes every document in ${cmd.delete}.`,
            };
        }
    }

    return null;
}

/** The first risky statement in the input, or null. */
export function riskyStatement(input: string, dialect: Dialect): Risk | null {
    if (dialect === 'redis') {
        return redisRisk(input);
    }

    if (dialect === 'mongo') {
        return mongoRisk(input);
    }

    for (const statement of stripSql(input).split(';')) {
        const risk = sqlRisk(statement);

        if (risk !== null) {
            return risk;
        }
    }

    return null;
}
