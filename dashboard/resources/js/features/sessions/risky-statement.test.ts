import { describe, expect, test } from 'vite-plus/test';
import { riskyStatement } from './risky-statement';

const sql = (s: string) => riskyStatement(s, 'sql')?.consequence ?? null;

describe('sql', () => {
    test('updates and deletes without WHERE', () => {
        expect(sql('DELETE FROM orders')).toBe(
            'This deletes every row in orders.',
        );
        expect(sql('delete from public."orders";')).toBe(
            'This deletes every row in orders.',
        );
        expect(sql("UPDATE users SET plan = 'pro'")).toBe(
            'This changes every row in users.',
        );
        expect(sql('DELETE FROM orders WHERE id = 1')).toBeNull();
        expect(sql('UPDATE users SET plan = 1 WHERE id = 2')).toBeNull();
    });

    test('drop, truncate and alter drop', () => {
        expect(sql('TRUNCATE TABLE sessions')).toBe('This empties sessions.');
        expect(sql('drop table if exists jobs')).toBe(
            'This drops the table jobs.',
        );
        expect(sql('ALTER TABLE users DROP COLUMN email')).toBe(
            'This drops part of users, with the data in it.',
        );
        expect(sql('ALTER TABLE users ADD COLUMN x int')).toBeNull();
    });

    test('keywords in strings and comments do not count; later statements do', () => {
        expect(sql("SELECT 'DELETE FROM orders'")).toBeNull();
        expect(sql('-- DROP TABLE users\nSELECT 1')).toBeNull();
        expect(sql('SELECT 1; DELETE FROM orders')).toBe(
            'This deletes every row in orders.',
        );
        expect(sql("DELETE FROM t WHERE note = 'x'; SELECT 1")).toBeNull();
    });
});

test('redis and mongo', () => {
    expect(riskyStatement('flushall', 'redis')).not.toBeNull();
    expect(riskyStatement('DEL user:1', 'redis')).toBeNull();
    expect(riskyStatement('{"drop": "users"}', 'mongo')?.consequence).toBe(
        'This drops the collection users.',
    );
    expect(
        riskyStatement(
            '{"delete": "users", "deletes": [{"q": {}, "limit": 0}]}',
            'mongo',
        ),
    ).not.toBeNull();
    expect(
        riskyStatement(
            '{"delete": "users", "deletes": [{"q": {"a": 1}, "limit": 0}]}',
            'mongo',
        ),
    ).toBeNull();
    expect(riskyStatement('{"find": "users"}', 'mongo')).toBeNull();
});
