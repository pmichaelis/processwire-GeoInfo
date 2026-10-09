# GeoInfo

## ProcessWire - GeoInfo

Location data for [ProcessWire](https://processwire.com):

- **By IP address** from a local database: country, region, city, coordinates, continent. No visitor data leaves your server.
- **By coordinates** from [OpenStreetMap Nominatim](https://nominatim.org): nearest place, region, country and distance. Results are cached.

```php
$geoInfo = $modules->get('GeoInfo');

echo $geoInfo->ip()?->countryCode;                    // "DE"
echo $geoInfo->place(52.52, 13.405)?->place;          // "Berlin"
```

The IP database is the free [DB-IP City Lite](https://db-ip.com/db/download/ip-to-city-lite) database, which the module downloads and updates every month. You can also use MaxMind GeoLite2 City or any other compatible MMDB file.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Usage](#usage)
  - [Location by IP address](#location-by-ip-address)
  - [Place by coordinates](#place-by-coordinates)
- [Settings](#settings)
- [Using MaxMind GeoLite2](#using-maxmind-geolite2)
- [Attribution](#attribution)
- [Privacy](#privacy)
- [Upgrading from 1.x](#upgrading-from-1x)
- [Logs](#logs)
- [Translations](#translations)
- [Updating dependencies](#updating-dependencies)
- [License](#license)

## Requirements

- PHP 8.1 or later with the zlib extension (to unpack the downloaded database)
- ProcessWire 3.0.200 or later
- About 125 MB of disk space for the IP database. During an update, up to 300 MB are needed temporarily.
- Outgoing HTTPS connections to `download.db-ip.com` (database downloads) and `nominatim.openstreetmap.org` (`place()`)
- [LazyCron](https://processwire.com/modules/lazy-cron/) (core module) for automatic monthly updates. This is optional.

## Installation

All dependencies are included in the `vendor/` directory, so Composer is not required.

1. Place the folder in `site/modules/` so that the module file is located at
   `site/modules/GeoInfo/GeoInfo.module.php`.
2. In the admin, go to **Modules > Refresh**.
3. Find **GeoInfo** in the list and click **Install**.
4. In the module settings, check **Download now** and click **Submit**. The module then downloads the current DB-IP City Lite database to `site/assets/GeoInfo/` (about 60 MB compressed).

`place()` works right after installation. `ip()` returns `null` until the database has been downloaded.

## Usage

### Location by IP address

```php
$geoInfo = $modules->get('GeoInfo');

$geo = $geoInfo->ip();                 // current visitor
$geo = $geoInfo->ip('203.0.113.42');   // specific IPv4 or IPv6 address

if($geo) {
	echo $geo->countryCode;  // "DE"
	echo $geo->city;         // "Berlin"
}
```

`ip()` returns a `WireData` object, or `null` in these cases:

- the IP address is invalid
- the IP address is private or reserved, e.g. `192.168.x.x` or `127.0.0.1` (keep this in mind on local development setups)
- the IP address is not in the database
- no database is available

Lookups run against the local file, take well under a millisecond, and are cached for the rest of the request.

| Property | Description | Example |
| --- | --- | --- |
| `ip` | The IP address that was looked up | `8.8.8.8` |
| `city` | City | `Mountain View` |
| `region` | Region / state | `California` |
| `regionCode` | Region code (GeoLite2 only) | `CA` |
| `postalCode` | Postal code (GeoLite2 only) | `94043` |
| `countryCode` | ISO 3166-1 alpha-2 country code | `US` |
| `countryName` | Country name | `United States` |
| `inEU` | Country is a member of the European Union | `false` |
| `continentCode` | Continent code | `NA` |
| `continentName` | Continent name | `North America` |
| `latitude` | Latitude (approximate) | `37.422` |
| `longitude` | Longitude (approximate) | `-122.085` |
| `timezone` | Time zone (GeoLite2 only) | `America/Los_Angeles` |
| `source` | Database type | `DBIP-City-Lite` |
| `credit` | Attribution text of the database | `IP Geolocation by DB-IP (https://db-ip.com)` |

The names of cities, regions, countries and continents use the language set in the module settings. If a name is not available in that language, the English name is used. In DB-IP City Lite, only country and continent names are translated. City and region names are always in English.

### Place by coordinates

```php
$place = $modules->get('GeoInfo')->place(40.712784, -74.005941);

if($place) {
	echo $place->place;               // "New York"
	echo $place->countryCode;         // "US"
	echo $place->distanceKilometers;  // 5.31
}

// names in a specific language
$place = $modules->get('GeoInfo')->place(48.8566, 2.3522, 'de'); // "Paris", "Frankreich"
```

Latitude and longitude can be passed as floats or numeric strings. `place()` returns `null` in these cases:

- the coordinates are invalid
- the coordinates are outside of any place, e.g. on the open sea
- Nominatim cannot be reached

Results are cached for 30 days (WireCache), so repeated lookups of the same coordinates don't send another request. This includes coordinates for which nothing was found. Failed requests are not cached.

Nominatim returns the nearest administrative area, which is often a district. `place` contains the city, town or village of that area, while `latitude`, `longitude` and the distance refer to the area itself. In the example above, the area is Manhattan, so the distance is measured to the center of Manhattan.

| Property | Description | Example |
| --- | --- | --- |
| `place` | Nearest city, town or village | `New York` |
| `displayName` | Full name of the area found | `Manhattan, New York County, New York, United States` |
| `region` | Region / state | `New York` |
| `regionCode` | ISO 3166-2 region code | `US-NY` |
| `postalCode` | Postal code, if available | |
| `countryCode` | ISO 3166-1 alpha-2 country code | `US` |
| `countryName` | Country name | `United States` |
| `latitude` | Latitude of the area found | `40.7579554` |
| `longitude` | Longitude of the area found | `-73.9855319` |
| `distanceKilometers` | Distance between the given coordinates and the area found | `5.31` |
| `distanceMiles` | The same in miles | `3.3` |
| `credit` | Attribution text | `© OpenStreetMap contributors (…)` |

**Nominatim usage policy:** the public Nominatim server allows at most one request per second and does not allow bulk geocoding. Single lookups for page views are fine thanks to the cache. For heavy use, see the [Nominatim usage policy](https://operations.osmfoundation.org/policies/nominatim/) and consider running your own Nominatim server.

## Settings

Go to **Modules > Configure > GeoInfo**.

| Setting | Description | Default |
| --- | --- | --- |
| Status | Shows the database in use, its type, build date and size. | |
| Custom database file | Path to your own MMDB file of the type "City". Relative paths start at the ProcessWire root directory. Leave blank to use the managed DB-IP database. | blank |
| Update automatically | Downloads the new DB-IP City Lite database once a month via LazyCron. | on |
| Download now | Downloads the current DB-IP City Lite database when you save the settings. | |
| Language | ISO code for place names in the results of `ip()` and `place()`, e.g. `en` or `de`. | `en` |
| Trust proxy headers | Takes the visitor IP from the `X-Forwarded-For` / `Client-IP` header. Only enable this behind a reverse proxy or load balancer, otherwise visitors can fake their IP address. | off |
| Log errors | Writes errors to the log `geoinfo`. | on |

DB-IP publishes a new database at the beginning of each month. With automatic updates enabled, LazyCron checks once a day whether the current month is installed and downloads it if not. The download runs at the end of a regular page request and usually takes a few seconds.

If you prefer a real cron job, disable **Update automatically** and call the update yourself, e.g. once a day:

```php
$modules->get('GeoInfo')->updateDatabase();
```

## Using MaxMind GeoLite2

[GeoLite2 City](https://dev.maxmind.com/geoip/geolite2-free-geolocation-data) is often more accurate and also provides region codes, postal codes and time zones. It requires a free MaxMind account.

1. Download `GeoLite2-City.mmdb` from your MaxMind account. To keep it updated, use MaxMind's [geoipupdate](https://github.com/maxmind/geoipupdate) tool.
2. Place the file on your server **outside the web root**, e.g. in `/var/lib/GeoIP/GeoLite2-City.mmdb` (a default directory of geoipupdate). The GeoLite2 license does not allow redistribution. A file in `site/assets/` could be downloaded by anyone.
3. Enter the absolute path under **Custom database file** in the module settings.

The module does not update custom database files.

## Attribution

Both data sources require attribution on pages that show their data:

- **DB-IP City Lite** is licensed under [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/). Add a link such as `<a href="https://db-ip.com">IP Geolocation by DB-IP</a>`.
- **OpenStreetMap** data is licensed under the [ODbL](https://www.openstreetmap.org/copyright). Credit "© OpenStreetMap contributors".

The `credit` property of each result contains the matching text.

## Privacy

- `ip()` looks up IP addresses in a local file. No visitor data is sent to third parties.
- `place()` sends the coordinates to the OpenStreetMap Foundation (Nominatim). If these are a visitor's coordinates, e.g. from the browser's Geolocation API, mention this in your privacy policy.

## Upgrading from 1.x

Version 1.x used the geoPlugin web service. geoPlugin is no longer available for free, so version 1.x no longer works.

The old methods `$page->GeoInfoIP()` and `$page->GeoInfoLatLong()` still work. They return objects with the old `geoplugin_*` property names, so existing templates keep working. Please note the following differences:

- `geoplugin_areaCode`, `geoplugin_dmaCode` and the `geoplugin_currency*` fields are always `null`.
- New fields: `geoplugin_inEU`, `geoplugin_continentName` and `geoplugin_timezone`.
- `GeoInfoIP()` returns `geoplugin_status` `404` and empty fields if no result is available (private IP address, IP address not in the database, no database).
- The result is no longer stored in the session. Each IP address now gets its own result.
- The visitor IP is taken from `REMOTE_ADDR` by default. Enable **Trust proxy headers** if you need the old behavior.
- `GeoInfoLatLong()` now also accepts whole numbers such as `52` and returns the place from OpenStreetMap. Place names and distances can differ slightly from geoPlugin.

The old methods are deprecated. For new code, use `$modules->get('GeoInfo')->ip()` and `->place()`.

To upgrade:

1. Make sure the server runs PHP 8.1 or later.
2. Delete the old file `site/modules/GeoInfo/GeoInfo.module` and copy the new files into the folder.
3. Go to **Modules > Refresh**. ProcessWire updates the module to version 2.0.0.
4. Download the database in the module settings (see [Installation](#installation)).

## Logs

**Setup > Logs > geoinfo** contains database updates and, with **Log errors** enabled, failed downloads, unreadable database files and failed Nominatim requests.

## Translations

All texts in the admin are translatable with ProcessWire's language support. The source language is English.

To import the included German translation:

1. Go to **Modules > Site** (or **Modules > Configure**) and open **GeoInfo**.
2. In the module information, the row **Languages** lists `de`. Click **install translations**.
3. For each language that should use German, select `de` under **Import into ...** and submit.

For other languages, go to **Setup > Languages**, edit the language, click **Find Files to Translate** and select `site/modules/GeoInfo/GeoInfo.module.php`.

Place names in lookup results are not affected by this. They follow the **Language** setting of the module, see [Settings](#settings).

## Updating dependencies

The PHP library [maxmind-db/reader](https://github.com/maxmind/MaxMind-DB-Reader-php) is included in `vendor/`. To update it, run `composer update` in the module directory.

## License

MIT

Included third-party code:

- [maxmind-db/reader](https://github.com/maxmind/MaxMind-DB-Reader-php) (Apache-2.0) in `vendor/`
