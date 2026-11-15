import { describe, expect, test } from 'vite-plus/test';
import { PAGE_NAMES } from './pages';

describe('PAGE_NAMES', () => {
    test('lists exactly the page files', () => {
        const files = Object.keys(import.meta.glob('../pages/**/*.tsx'))
            .map((path) =>
                path.replace(/^\.\.\/pages\//, '').replace(/\.tsx$/, ''),
            )
            .sort();

        expect([...PAGE_NAMES].sort()).toEqual(files);
    });
});
