import { useEffect, useState } from 'react';

/** A one-second clock while an operation is running. */
export function useNow(running: boolean): number {
    const [now, setNow] = useState(Date.now);

    useEffect(() => {
        if (!running) {
            return;
        }

        const timer = window.setInterval(() => setNow(Date.now()), 1000);
        return () => window.clearInterval(timer);
    }, [running]);

    return now;
}
