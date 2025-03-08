import { router } from '@inertiajs/react';
import { useEffect } from 'react';

export default function useReloadOnChannel(channel: string) {
    useEffect(() => {
        window.Echo.private(channel).listenToAll(() => {
            console.log('Reloading page on channel event');
            router.reload();
        });
    }, [channel]);
}
