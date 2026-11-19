/**
 * The Backups page props (scratchpad contract with the backend, §1–§2).
 * Aliases of the generated `App.Data.Backups.*` types, so the page names
 * them in one place.
 */
export type BackupsPage = App.Data.Backups.BackupsPageData;
export type BackupsSummary = App.Data.Backups.BackupsSummaryData;
export type BackupGroup = App.Data.Backups.BackupGroupData;
export type BackupItem = App.Data.Backups.BackupItemData;
export type BackupRunProgress = App.Data.Backups.BackupRunProgressData;
export type BackupAction = App.Data.Backups.BackupActionData;
export type BackupDisabled = App.Data.Backups.BackupDisabledReasonData;
export type NotBackedUpItem = App.Data.Backups.NotBackedUpItemData;
export type BackupStorage = App.Data.Backups.BackupStorageData;
export type Destination = App.Data.Backups.BackupDestinationData;
export type RemovedDestination = App.Data.Backups.RemovedDestinationData;
export type LaunchroomBackup = App.Data.Backups.LaunchroomBackupData;
export type RecoveryKit = App.Data.Backups.RecoveryKitData;
export type BackupEverything = App.Data.Backups.BackupEverythingData;
export type BackupBatch = NonNullable<BackupEverything['batch']>;
