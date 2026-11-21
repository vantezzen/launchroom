export type ServiceTransfers = App.Data.Transfers.ServiceTransfersData;
export type Transfer = App.Data.Transfers.TransferData;
export type ImportRun = App.Data.Transfers.ImportRunData;
export type ExportRun = App.Data.Transfers.ExportRunData;
export type Inspection = Omit<
    App.Data.Transfers.ImportInspectionData,
    'status'
> & {
    status: 'pending' | 'done' | 'failed';
};
export type CopyRun = App.Data.Transfers.CopyRunData;
export type CopyStep = App.Data.Transfers.CopyStepData;
export type CopyOptions = App.Data.Transfers.CopyOptionsData;
export type CopySource = App.Data.Transfers.CopySourceData;
export type CopyInspection = {
    target: Inspection;
    source: Inspection | null;
};
