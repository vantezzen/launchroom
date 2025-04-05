import { config } from 'dotenv';
import SmeeClient from 'smee-client';
config();

// eslint-disable-next-line no-undef
const SMEE_URL = process.env.SMEE_URL;

console.log('Starting Smee client...');
console.log(`
> For testing webhooks locally, you can use Smee.io to forward events from GitHub to your local server.
Smee URL: ${SMEE_URL}
`);

const smee = new SmeeClient({
    source: SMEE_URL,
    target: 'http://localhost:8000/api/hooks/github',
    logger: console,
});

smee.start();
