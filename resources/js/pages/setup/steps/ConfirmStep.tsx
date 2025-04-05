import FormErrors from '@/components/ui/form-errors';
import { SetupForm } from '..';

function ConfirmStep({ form }: { form: SetupForm }) {
    return (
        <div className="space-y-6">
            <div className="flex flex-col items-center justify-center">
                <h1 className="mb-4 text-center text-2xl font-bold">Confirm</h1>
                <p className="mb-4 text-zinc-500">
                    Please confirm your settings before proceeding. You can always change them later in the dashboard.
                </p>
            </div>

            <div className="space-y-4 rounded-lg bg-gray-50 p-4">
                <h3 className="font-medium">Instance Details</h3>
                <div className="grid grid-cols-2 gap-2 text-sm">
                    <div className="text-muted-foreground">Instance Name:</div>
                    <div>{form.data.name}</div>

                    <div className="text-muted-foreground">IPv4 Address:</div>
                    <div>{form.data.ipv4}</div>

                    {form.data.ipv6 && (
                        <>
                            <div className="text-muted-foreground">IPv6 Address:</div>
                            <div>{form.data.ipv6}</div>
                        </>
                    )}

                    <div className="text-muted-foreground">Public Access:</div>
                    <div>{form.data.publicly_accessible ? 'Yes' : 'No'}</div>

                    {form.data.domain && (
                        <>
                            <div className="text-muted-foreground">Domain:</div>
                            <div>{form.data.domain}</div>
                        </>
                    )}

                    <div className="text-muted-foreground">Admin Email:</div>
                    <div>{form.data.email}</div>
                </div>
            </div>

            <FormErrors errors={form.errors} />
        </div>
    );
}

export default ConfirmStep;
