const URLS = {
    'http://laravel-demo-production.127.0.0.1.sslip.io/': 10,
    'http://laravel-demo-production.127.0.0.1.sslip.io/database': 5,
    'http://laravel-demo-production.127.0.0.1.sslip.io/cache': 3,
    'http://laravel-demo-production.127.0.0.1.sslip.io/queue': 1,
};
const SECONDS_PER_ROUND = 5;

const generateTraffic = async () => {
    for (const [url, times] of Object.entries(URLS)) {
        for (let i = 0; i < times; i++) {
            fetch(url)
                .then(() => console.log(`Successfully fetched ${url}`))
                .catch((error) => console.error(`Error fetching ${url}: ${error}`));
        }
    }
};

setInterval(generateTraffic, SECONDS_PER_ROUND * 1000);
