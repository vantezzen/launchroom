export {};

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: App.Data.Inertia.SharedPropsData;
        flashDataType: { toast?: App.Data.Inertia.ToastData };
    }
}
