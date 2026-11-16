import { expect, test } from 'vite-plus/test';
import { exportFileName, toCsv, toJson } from './export';

test('csv quotes what needs quoting and leaves NULL empty', () => {
    expect(
        toCsv(
            ['id', 'note'],
            [
                ['1', 'plain'],
                ['2', 'a, "b"'],
                ['3', null],
                ['4', 'two\nlines'],
            ],
        ),
    ).toBe('id,note\r\n1,plain\r\n2,"a, ""b"""\r\n3,\r\n4,"two\nlines"');
});

test('json keys rows by column and keeps duplicate columns apart', () => {
    expect(JSON.parse(toJson(['id', 'id', 'x'], [['1', '2', null]]))).toEqual([
        { id: '1', id_2: '2', x: null },
    ]);
});

test('file names are slugs', () => {
    expect(exportFileName('Public.Orders', 1, 50)).toBe(
        'public-orders-rows-1-50.csv',
    );
    expect(exportFileName('', 1, 3)).toBe('result-rows-1-3.csv');
});
