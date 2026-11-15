import { describe, expect, it } from 'vitest';
import { ROUTE } from './shortcuts';

const prj = 'prj_01JB7Z6X9Q3T8M5N2P4R6S8V0W';
const env = 'env_01JB7Z6X9Q3T8M5N2P4R6S8V0X';
const svc = 'svc_01JB7Z6X9Q3T8M5N2P4R6S8V0Y';

describe('ROUTE', () => {
    it('reads the project, environment and service of a canvas URL', () => {
        expect(ROUTE.exec(`/p/${prj}/${env}/s/${svc}`)?.slice(1)).toEqual([
            prj,
            env,
            svc,
        ]);
        expect(ROUTE.exec(`/p/${prj}`)?.[1]).toBe(prj);
    });

    it('uses the protocol ULID grammar', () => {
        expect(ROUTE.exec('/p/prj_01JB7Z6X9Q3T8M5N2P4R6S8V0I')).toBeNull();
        expect(ROUTE.exec('/p/prj_81JB7Z6X9Q3T8M5N2P4R6S8V0W')).toBeNull();
    });
});
