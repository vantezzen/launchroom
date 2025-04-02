import { intlFormatDistance } from 'date-fns';

export const timeAgo = (date: string) => {
    const now = new Date();
    const updated = new Date(date);

    return intlFormatDistance(updated, now);
};

export const prometheusDurationToSeconds = (duration: string) => {
    const matches = duration.match(/(\d+)([smhd])/);
    if (!matches) return 0;

    const [, value, unit] = matches;
    const multiplier = {
        s: 1,
        m: 60,
        h: 60 * 60,
        d: 60 * 60 * 24,
    };

    return parseInt(value, 10) * multiplier[unit as 's' | 'm' | 'h' | 'd'];
};
