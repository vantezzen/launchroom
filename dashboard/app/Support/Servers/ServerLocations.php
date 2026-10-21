<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Data\Servers\LocationData;
use App\Models\Server;
use DateTimeZone;
use Throwable;

/**
 * Where a server is, for the map and the card chip (docs/design/direction.md
 * §7.3, maintainer decision 6). In order:
 *
 * 1. the location the user confirmed or entered (`servers.location`);
 * 2. a region code in the server's `zone`/`region` label or its name
 *    ("fsn1", "fra-1", "us-east-1");
 * 3. the host's time zone (`Europe/Berlin` → Berlin), from PHP's bundled tz
 *    database (coordinates of the zone's main city).
 *
 * 2 and 3 are suggestions the user confirms once. No IP geolocation: a
 * self-hosted install never phones home.
 */
final class ServerLocations
{
    /**
     * Data-centre region codes of common providers → [label, latitude, longitude, country].
     *
     * @var array<string, array{0: string, 1: float, 2: float, 3: string}>
     */
    private const array REGIONS = [
        // Hetzner
        'fsn' => ['Falkenstein', 50.48, 12.37, 'DE'],
        'nbg' => ['Nuremberg', 49.45, 11.08, 'DE'],
        'hel' => ['Helsinki', 60.17, 24.94, 'FI'],
        'ash' => ['Ashburn', 39.04, -77.49, 'US'],
        'hil' => ['Hillsboro', 45.52, -122.99, 'US'],
        'sin' => ['Singapore', 1.35, 103.82, 'SG'],
        // Common IATA-style city codes (DigitalOcean, Vultr, Linode, UpCloud, Scaleway …)
        'fra' => ['Frankfurt', 50.11, 8.68, 'DE'],
        'ams' => ['Amsterdam', 52.37, 4.90, 'NL'],
        'lon' => ['London', 51.51, -0.13, 'GB'],
        'lhr' => ['London', 51.51, -0.13, 'GB'],
        'par' => ['Paris', 48.86, 2.35, 'FR'],
        'cdg' => ['Paris', 48.86, 2.35, 'FR'],
        'waw' => ['Warsaw', 52.23, 21.01, 'PL'],
        'mad' => ['Madrid', 40.42, -3.70, 'ES'],
        'mil' => ['Milan', 45.46, 9.19, 'IT'],
        'sto' => ['Stockholm', 59.33, 18.07, 'SE'],
        'nyc' => ['New York', 40.71, -74.01, 'US'],
        'ewr' => ['New Jersey', 40.74, -74.17, 'US'],
        'sfo' => ['San Francisco', 37.77, -122.42, 'US'],
        'sjc' => ['San Jose', 37.34, -121.89, 'US'],
        'lax' => ['Los Angeles', 34.05, -118.24, 'US'],
        'sea' => ['Seattle', 47.61, -122.33, 'US'],
        'ord' => ['Chicago', 41.88, -87.63, 'US'],
        'dfw' => ['Dallas', 32.78, -96.80, 'US'],
        'atl' => ['Atlanta', 33.75, -84.39, 'US'],
        'mia' => ['Miami', 25.76, -80.19, 'US'],
        'tor' => ['Toronto', 43.65, -79.38, 'CA'],
        'yto' => ['Toronto', 43.65, -79.38, 'CA'],
        'blr' => ['Bangalore', 12.97, 77.59, 'IN'],
        'bom' => ['Mumbai', 19.08, 72.88, 'IN'],
        'syd' => ['Sydney', -33.87, 151.21, 'AU'],
        'nrt' => ['Tokyo', 35.68, 139.69, 'JP'],
        'tyo' => ['Tokyo', 35.68, 139.69, 'JP'],
        'icn' => ['Seoul', 37.57, 126.98, 'KR'],
        'gru' => ['São Paulo', -23.55, -46.63, 'BR'],
        'jnb' => ['Johannesburg', -26.20, 28.05, 'ZA'],
        // AWS
        'us-east-1' => ['N. Virginia', 38.95, -77.45, 'US'],
        'us-east-2' => ['Ohio', 40.00, -83.00, 'US'],
        'us-west-1' => ['N. California', 37.35, -121.96, 'US'],
        'us-west-2' => ['Oregon', 45.84, -119.70, 'US'],
        'eu-central-1' => ['Frankfurt', 50.11, 8.68, 'DE'],
        'eu-central-2' => ['Zurich', 47.38, 8.54, 'CH'],
        'eu-west-1' => ['Dublin', 53.35, -6.26, 'IE'],
        'eu-west-2' => ['London', 51.51, -0.13, 'GB'],
        'eu-west-3' => ['Paris', 48.86, 2.35, 'FR'],
        'eu-north-1' => ['Stockholm', 59.33, 18.07, 'SE'],
        'eu-south-1' => ['Milan', 45.46, 9.19, 'IT'],
        'ap-southeast-1' => ['Singapore', 1.35, 103.82, 'SG'],
        'ap-southeast-2' => ['Sydney', -33.87, 151.21, 'AU'],
        'ap-northeast-1' => ['Tokyo', 35.68, 139.69, 'JP'],
        'ap-south-1' => ['Mumbai', 19.08, 72.88, 'IN'],
        'sa-east-1' => ['São Paulo', -23.55, -46.63, 'BR'],
        'ca-central-1' => ['Montreal', 45.50, -73.57, 'CA'],
    ];

    public static function for(Server $server): ?LocationData
    {
        $stored = self::stored($server->location);

        if ($stored instanceof LocationData) {
            return $stored;
        }

        $labels = $server->labels;

        foreach ([$labels['region'] ?? null, $labels['zone'] ?? null, $server->name] as $candidate) {
            $region = is_string($candidate) ? self::region($candidate) : null;

            if ($region instanceof LocationData) {
                return $region;
            }
        }

        $timezone = $server->facts->hardware?->timezone;

        return $timezone === null ? null : self::timezone($timezone);
    }

    /**
     * A region code in a label or server name: the whole value ("us-east-1",
     * "fsn1-dc14") or a dash/dot separated token with an optional number
     * ("fra-1", "web.nbg1").
     */
    public static function region(string $value): ?LocationData
    {
        $value = mb_strtolower(trim($value));

        if (isset(self::REGIONS[$value])) {
            return self::fromRegion($value);
        }

        if (preg_match('/^([a-z]{2}-[a-z]+-\d)[a-z]?$/', $value, $m) === 1 && isset(self::REGIONS[$m[1]])) {
            return self::fromRegion($m[1]);
        }

        $tokens = preg_split('/[^a-z0-9]+/', $value) ?: [];

        foreach ($tokens as $i => $token) {
            // "fsn1", "fra1", or "fra" followed by a number token ("fra-1").
            if (preg_match('/^([a-z]{3})\d{0,2}$/', $token, $m) !== 1 || ! isset(self::REGIONS[$m[1]])) {
                continue;
            }

            $numbered = $m[1] !== $token || (isset($tokens[$i + 1]) && ctype_digit($tokens[$i + 1]));

            if ($numbered) {
                return self::fromRegion($m[1]);
            }
        }

        return null;
    }

    /**
     * The main city of an IANA zone ("Europe/Berlin" → Berlin). UTC and
     * other non-geographic zones give nothing.
     */
    public static function timezone(string $timezone): ?LocationData
    {
        if (preg_match('#^(Africa|America|Antarctica|Asia|Atlantic|Australia|Europe|Indian|Pacific)/#', $timezone) !== 1) {
            return null;
        }

        try {
            $location = new DateTimeZone($timezone)->getLocation();
        } catch (Throwable) {
            return null;
        }

        if ($location === false) {
            return null;
        }

        $parts = explode('/', $timezone);
        $city = str_replace('_', ' ', end($parts));
        $country = preg_match('/^[A-Z]{2}$/', $location['country_code']) === 1 ? $location['country_code'] : null;

        return new LocationData($city, round($location['latitude'], 2), round($location['longitude'], 2), $country, LocationData::SOURCE_TIMEZONE, false);
    }

    /**
     * @param  array<array-key, mixed>|null  $location  `servers.location`
     */
    private static function stored(?array $location): ?LocationData
    {
        if ($location === null || ! is_string($location['label'] ?? null) || ! is_numeric($location['latitude'] ?? null) || ! is_numeric($location['longitude'] ?? null)) {
            return null;
        }

        $country = $location['countryCode'] ?? null;

        return new LocationData($location['label'], (float) $location['latitude'], (float) $location['longitude'], is_string($country) ? $country : null, LocationData::SOURCE_USER, true);
    }

    private static function fromRegion(string $code): LocationData
    {
        [$label, $lat, $lon, $country] = self::REGIONS[$code];

        return new LocationData($label, $lat, $lon, $country, LocationData::SOURCE_REGION, false);
    }
}
