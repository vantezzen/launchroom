import { LAND_GRID } from './land-dots.generated';

/** lon/lat → land-dot grid units (column/row centres), matching land-dots.generated.ts. */
export function project(lat: number, lon: number): { x: number; y: number } {
    const { step, latMax, lonMin } = LAND_GRID;
    const shifted = lon < lonMin ? lon + 360 : lon;

    return {
        x: (shifted - lonMin - step / 2) / step,
        y: (latMax - step / 2 - lat) / step,
    };
}
