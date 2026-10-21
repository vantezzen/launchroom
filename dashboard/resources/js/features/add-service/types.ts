export type AddServicePayload = Partial<
    Omit<App.Data.Canvas.AddServiceData, 'name' | 'kind'>
> &
    Pick<App.Data.Canvas.AddServiceData, 'name' | 'kind'>;

export type SubmitService = (payload: AddServicePayload) => void;
