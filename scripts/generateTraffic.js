const URLS = [
    'http://laravel-demo-production.127.0.0.1.sslip.io/',
    'http://laravel-demo-production.127.0.0.1.sslip.io/database',
    'http://laravel-demo-production.127.0.0.1.sslip.io/cache',
    'http://laravel-demo-production.127.0.0.1.sslip.io/queue',
];
const requests = 10000;

const generateTraffic = async () => {
    const randomIndex = Math.floor(Math.random() * URLS.length);
    const url = URLS[randomIndex];
    console.log(`Sending request to ${url}`);
    try {
        await fetch(url);
        console.log(`Request to ${url} sent successfully`);
    } catch (error) {
        console.error(`Error sending request to ${url}: ${error}`);
    }
};

for (let i = 0; i < requests; i++) {
    generateTraffic();
}
