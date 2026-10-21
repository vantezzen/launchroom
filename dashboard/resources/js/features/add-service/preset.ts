import { openAddService } from '@/features/projects/add-service-store';

export type AddChoice =
    | 'github'
    | 'catalog'
    | 'image'
    | 'database'
    | 'worker'
    | 'cron';

let preset: AddChoice | null = null;

/** Opens the add sheet on a given choice ("Add a database" ghost tile). */
export function openAddServiceWith(choice: AddChoice): void {
    preset = choice;
    openAddService();
}

/** The pending preset, once (the sheet reads it when it opens). */
export function takePreset(): AddChoice | null {
    const value = preset;
    preset = null;

    return value;
}
