import mysqlIcon from '@/assets/services/mysql.png';
import postgresqlIcon from '@/assets/services/postgresql.png';
import redisIcon from '@/assets/services/redis.svg';

export type AvailableService = {
    category: string;
    type: string;
    name: string;
    icon: string;
};
export const AVAILABLE_SERVICES: AvailableService[] = [
    {
        category: 'database',
        type: 'mysql',
        name: 'MySQL',
        icon: mysqlIcon,
    },
    {
        category: 'database',
        type: 'postgresql',
        name: 'PostgreSQL',
        icon: postgresqlIcon,
    },
    {
        category: 'cache',
        type: 'redis',
        name: 'Redis',
        icon: redisIcon,
    },
];
