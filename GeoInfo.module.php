<?php namespace ProcessWire;

use MaxMind\Db\Reader;

/**
 * GeoInfo
 *
 * Location data for ProcessWire:
 *
 * - by IP address from a local MMDB database (DB-IP City Lite, MaxMind GeoLite2 City or compatible)
 * - by coordinates from OpenStreetMap Nominatim (reverse geocoding, results are cached)
 *
 * ~~~~~
 * $geoInfo = $modules->get('GeoInfo');
 * $geo = $geoInfo->ip();                            // current visitor
 * $place = $geoInfo->place(40.712784, -74.005941);
 * ~~~~~
 *
 * Dependencies are included in vendor/, to update run "composer update" in this directory.
 *
 * @author Philipp Michaelis (pmichaelis)
 * @license MIT
 * @link https://github.com/pmichaelis/GeoInfo
 *
 * @property string $dbFile Path to a custom MMDB file, blank to use the managed DB-IP City Lite database
 * @property int $autoUpdate Update the managed database monthly via LazyCron
 * @property int $trustProxy Use the X-Forwarded-For / Client-IP header to detect the visitor IP
 * @property string $language ISO language code for place names
 * @property int $log Log errors
 *
 */
class GeoInfo extends WireData implements Module, ConfigurableModule {

	public static function getModuleInfo() {
		return array(
			'title' => __('GeoInfo', __FILE__),
			'summary' => __('Location data by IP address (local database) and by coordinates (OpenStreetMap Nominatim).', __FILE__),
			'version' => '2.0.0',
			'author' => 'Philipp Michaelis (pmichaelis)',
			'href' => 'https://github.com/pmichaelis/GeoInfo',
			'icon' => 'globe',
			'autoload' => true,
			'singular' => true,
			'requires' => array('PHP>=8.1.0', 'ProcessWire>=3.0.200'),
		);
	}

	/**
	 * Name of the managed DB-IP database file
	 *
	 */
	const dbName = 'dbip-city-lite.mmdb';

	/**
	 * Download URL of DB-IP City Lite, {month} is replaced with YYYY-MM
	 *
	 */
	const dbUrl = 'https://download.db-ip.com/free/dbip-city-lite-{month}.mmdb.gz';

	/**
	 * Nominatim reverse geocoding endpoint
	 *
	 */
	const nominatimUrl = 'https://nominatim.openstreetmap.org/reverse';

	/**
	 * Seconds a Nominatim result stays cached
	 *
	 */
	const placeLifetime = 2592000; // 30 days

	/**
	 * Name of the log file
	 *
	 */
	const logName = 'geoinfo';

	/**
	 * @var Reader|null
	 *
	 */
	protected $reader = null;

	/**
	 * IP lookups of the current request as [ip => WireData|null]
	 *
	 * @var array
	 *
	 */
	protected $ipResults = array();

	public function __construct() {
		parent::__construct();
		$this->set('dbFile', '');
		$this->set('autoUpdate', 1);
		$this->set('trustProxy', 0);
		$this->set('language', 'en');
		$this->set('log', 1);
	}

	public function init() {
		// aliases for version 1.x
		$this->addHook('Page::GeoInfoIP', $this, 'hookGeoInfoIP');
		$this->addHook('Page::GeoInfoLatLong', $this, 'hookGeoInfoLatLong');
		if($this->autoUpdate && !$this->dbFile) {
			$this->addHookAfter('LazyCron::everyDay', $this, 'hookLazyCron');
		}
	}

	/*** API ****************************************************************************/

	/**
	 * Get location data for an IP address
	 *
	 * Returns null when the IP address is invalid, private or not in the database,
	 * or when no database is available.
	 *
	 * @param string|null $ip IP address, omit for the current visitor
	 * @return WireData|null
	 *
	 */
	public function ip($ip = null) {
		$ip = $ip === null || $ip === '' ? $this->getVisitorIP() : trim((string) $ip);
		if(!filter_var($ip, FILTER_VALIDATE_IP)) return null;
		if(array_key_exists($ip, $this->ipResults)) return $this->ipResults[$ip];

		$result = null;
		$reader = $this->getReader();
		if($reader) {
			try {
				$record = $reader->get($ip);
			} catch(\Throwable $e) {
				$record = null;
				$this->logError('IP lookup failed: ' . $e->getMessage());
			}
			if(is_array($record)) $result = $this->buildIPResult($ip, $record, $reader->metadata()->databaseType);
		}

		$this->ipResults[$ip] = $result;
		return $result;
	}

	/**
	 * Get the nearest place for coordinates (reverse geocoding)
	 *
	 * Returns null when the coordinates are invalid or Nominatim has no result or
	 * cannot be reached. Results are cached for 30 days.
	 *
	 * @param float|string $lat Latitude, -90 to 90
	 * @param float|string $lon Longitude, -180 to 180
	 * @param string $language ISO language code for names, omit to use the module setting
	 * @return WireData|null
	 *
	 */
	public function place($lat, $lon, $language = '') {
		if(!is_numeric($lat) || !is_numeric($lon)) return null;
		$lat = (float) $lat;
		$lon = (float) $lon;
		if($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) return null;
		$language = $this->sanitizeLanguage($language ? $language : $this->language);

		$cache = $this->wire()->cache;
		$key = 'place-' . sha1(sprintf('%.5F,%.5F,%s', $lat, $lon, $language));
		$data = $cache->getFor($this, $key);

		if(!is_array($data)) {
			$data = $this->requestPlace($lat, $lon, $language);
			if($data === null) return null;
			$cache->saveFor($this, $key, $data, self::placeLifetime);
		}
		if(empty($data['place_id'])) return null; // cached "nothing found"

		return $this->buildPlaceResult($lat, $lon, $data);
	}

	/**
	 * Get the IP address of the current visitor
	 *
	 * Uses the X-Forwarded-For / Client-IP header only when "Trust proxy headers" is enabled.
	 *
	 * @return string
	 *
	 */
	public function getVisitorIP() {
		return (string) $this->wire()->session->getIP(false, (bool) $this->trustProxy);
	}

	/**
	 * Get the path of the database file in use
	 *
	 * @return string
	 *
	 */
	public function getDatabaseFile() {
		if(!$this->dbFile) return $this->getManagedDir() . self::dbName;
		$file = $this->dbFile;
		if(strpos($file, '/') !== 0 && !preg_match('{^[a-zA-Z]:[\\\\/]}', $file)) {
			$file = $this->wire()->config->paths->root . ltrim($file, '/');
		}
		return $file;
	}

	/**
	 * Download the current DB-IP City Lite database
	 *
	 * Tries the current month first, then the previous month (a new month is published
	 * during the first days of the month). Only applies to the managed database.
	 *
	 * @param bool $fallback Try the previous month if the current one is not available yet
	 * @return bool
	 *
	 */
	public function ___updateDatabase($fallback = true) {
		if($this->dbFile) return false;

		$files = $this->wire()->files;
		$dir = $this->getManagedDir();
		if(!is_dir($dir)) $files->mkdir($dir, true);
		$gzFile = $dir . self::dbName . '.gz';
		$tmpFile = $dir . self::dbName . '.tmp';

		$months = array(date('Y-m'));
		if($fallback) $months[] = date('Y-m', strtotime('first day of last month'));

		foreach($months as $month) {
			$url = str_replace('{month}', $month, self::dbUrl);
			try {
				@set_time_limit(300);
				$http = new WireHttp();
				$this->wire($http);
				$http->download($url, $gzFile, array('timeout' => 120));
				$this->gunzip($gzFile, $tmpFile);
				// make sure the file is a valid database before replacing the current one
				$this->loadVendor();
				$reader = new Reader($tmpFile);
				$reader->close();
			} catch(\Throwable $e) {
				if(is_file($gzFile)) $files->unlink($gzFile);
				if(is_file($tmpFile)) $files->unlink($tmpFile);
				$this->logError("Download of $url failed: " . $e->getMessage());
				continue;
			}

			$files->unlink($gzFile);
			if($this->reader) {
				$this->reader->close();
				$this->reader = null;
			}
			$files->rename($tmpFile, $dir . self::dbName);
			$this->wire()->cache->saveFor($this, 'db-month', $month, WireCache::expireNever);
			$this->ipResults = array();
			$this->wire()->log->save(self::logName, "Database updated to DB-IP City Lite $month");
			return true;
		}

		return false;
	}

	/*** INTERNALS **********************************************************************/

	/**
	 * Load composer dependencies
	 *
	 * @return bool
	 *
	 */
	protected function loadVendor() {
		if(class_exists(Reader::class, false)) return true;
		$file = __DIR__ . '/vendor/autoload.php';
		if(!is_file($file)) {
			throw new WireException($this->_('GeoInfo: Dependencies are missing, please run "composer install" in the module directory.'));
		}
		require_once($file);
		return true;
	}

	/**
	 * Get directory of the managed database
	 *
	 * @return string
	 *
	 */
	protected function getManagedDir() {
		return $this->wire()->config->paths->assets . 'GeoInfo/';
	}

	/**
	 * Get the database reader
	 *
	 * @return Reader|null Null when no database is available
	 *
	 */
	protected function getReader() {
		if($this->reader) return $this->reader;
		$file = $this->getDatabaseFile();
		if(!is_file($file)) return null;
		try {
			$this->loadVendor();
			$this->reader = new Reader($file);
		} catch(\Throwable $e) {
			$this->logError("Cannot open database $file: " . $e->getMessage());
			return null;
		}
		return $this->reader;
	}

	/**
	 * Decompress a .gz file
	 *
	 * @param string $from
	 * @param string $to
	 * @throws WireException
	 *
	 */
	protected function gunzip($from, $to) {
		$in = gzopen($from, 'rb');
		$out = fopen($to, 'wb');
		if(!$in || !$out) throw new WireException('Cannot decompress database');
		while(!gzeof($in)) {
			$chunk = gzread($in, 1048576);
			if($chunk === false) throw new WireException('Cannot decompress database');
			fwrite($out, $chunk);
		}
		gzclose($in);
		fclose($out);
	}

	/**
	 * Build the result of an IP lookup
	 *
	 * Works with the City databases of DB-IP and MaxMind, which share the same structure.
	 *
	 * @param string $ip
	 * @param array $record
	 * @param string $databaseType
	 * @return WireData
	 *
	 */
	protected function buildIPResult($ip, array $record, $databaseType) {
		$subdivision = isset($record['subdivisions'][0]) ? $record['subdivisions'][0] : array();
		$result = new WireData();
		$result->setArray(array(
			'ip' => $ip,
			'city' => $this->getName($record, 'city'),
			'region' => $this->getName(array('subdivision' => $subdivision), 'subdivision'),
			'regionCode' => isset($subdivision['iso_code']) ? $subdivision['iso_code'] : null,
			'postalCode' => isset($record['postal']['code']) ? $record['postal']['code'] : null,
			'countryCode' => isset($record['country']['iso_code']) ? $record['country']['iso_code'] : null,
			'countryName' => $this->getName($record, 'country'),
			'inEU' => !empty($record['country']['is_in_european_union']),
			'continentCode' => isset($record['continent']['code']) ? $record['continent']['code'] : null,
			'continentName' => $this->getName($record, 'continent'),
			'latitude' => isset($record['location']['latitude']) ? (float) $record['location']['latitude'] : null,
			'longitude' => isset($record['location']['longitude']) ? (float) $record['location']['longitude'] : null,
			'timezone' => isset($record['location']['time_zone']) ? $record['location']['time_zone'] : null,
			'source' => $databaseType,
			'credit' => stripos($databaseType, 'dbip') !== false || stripos($databaseType, 'db-ip') !== false
				? 'IP Geolocation by DB-IP (https://db-ip.com)'
				: 'GeoLite2 data created by MaxMind (https://www.maxmind.com)',
		));
		return $result;
	}

	/**
	 * Get the localized name of a record part, falling back to English
	 *
	 * @param array $record
	 * @param string $part
	 * @return string|null
	 *
	 */
	protected function getName(array $record, $part) {
		if(empty($record[$part]['names']) || !is_array($record[$part]['names'])) return null;
		$names = $record[$part]['names'];
		$language = $this->sanitizeLanguage($this->language);
		if(isset($names[$language])) return $names[$language];
		if(isset($names['en'])) return $names['en'];
		return reset($names);
	}

	/**
	 * Query Nominatim
	 *
	 * @param float $lat
	 * @param float $lon
	 * @param string $language
	 * @return array|null Response data (empty array when nothing was found), null on errors
	 *
	 */
	protected function requestPlace($lat, $lon, $language) {
		$config = $this->wire()->config;
		$query = array(
			'format' => 'jsonv2',
			'lat' => sprintf('%.6F', $lat),
			'lon' => sprintf('%.6F', $lon),
			'zoom' => 10,
			'addressdetails' => 1,
		);
		if($language) $query['accept-language'] = $language;
		$url = self::nominatimUrl . '?' . http_build_query($query);

		$http = new WireHttp();
		$this->wire($http);
		$http->setTimeout(5);
		$http->setHeader('User-Agent', 'ProcessWire GeoInfo/2.0 (' . $config->urls->httpRoot . ')');
		$json = $http->get($url);
		$code = (int) $http->getHttpCode();

		if($json === false || $code !== 200) {
			$this->logError("Nominatim request failed (HTTP $code): " . $http->getError());
			return null;
		}
		$data = json_decode($json, true);
		if(!is_array($data)) {
			$this->logError('Nominatim returned invalid JSON');
			return null;
		}
		if(isset($data['error'])) return array(); // e.g. "Unable to geocode" (open sea)
		return $data;
	}

	/**
	 * Build the result of a reverse geocoding lookup
	 *
	 * @param float $lat Requested latitude
	 * @param float $lon Requested longitude
	 * @param array $data Nominatim response
	 * @return WireData
	 *
	 */
	protected function buildPlaceResult($lat, $lon, array $data) {
		$address = isset($data['address']) && is_array($data['address']) ? $data['address'] : array();
		$place = null;
		foreach(array('city', 'town', 'village', 'municipality', 'hamlet', 'suburb', 'county') as $key) {
			if(empty($address[$key])) continue;
			$place = $address[$key];
			break;
		}
		if($place === null && !empty($data['name'])) $place = $data['name'];

		$regionCode = isset($address['ISO3166-2-lvl4']) ? $address['ISO3166-2-lvl4'] : null;
		$placeLat = isset($data['lat']) ? (float) $data['lat'] : null;
		$placeLon = isset($data['lon']) ? (float) $data['lon'] : null;
		$km = $placeLat === null || $placeLon === null ? null : $this->distance($lat, $lon, $placeLat, $placeLon);

		$result = new WireData();
		$result->setArray(array(
			'place' => $place,
			'displayName' => isset($data['display_name']) ? $data['display_name'] : null,
			'region' => isset($address['state']) ? $address['state'] : null,
			'regionCode' => $regionCode,
			'postalCode' => isset($address['postcode']) ? $address['postcode'] : null,
			'countryCode' => isset($address['country_code']) ? strtoupper($address['country_code']) : null,
			'countryName' => isset($address['country']) ? $address['country'] : null,
			'latitude' => $placeLat,
			'longitude' => $placeLon,
			'distanceKilometers' => $km === null ? null : round($km, 2),
			'distanceMiles' => $km === null ? null : round($km / 1.609344, 2),
			'credit' => '© OpenStreetMap contributors (https://www.openstreetmap.org/copyright)',
		));
		return $result;
	}

	/**
	 * Great-circle distance in kilometers (haversine)
	 *
	 * @param float $lat1
	 * @param float $lon1
	 * @param float $lat2
	 * @param float $lon2
	 * @return float
	 *
	 */
	protected function distance($lat1, $lon1, $lat2, $lon2) {
		$dLat = deg2rad($lat2 - $lat1);
		$dLon = deg2rad($lon2 - $lon1);
		$a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
		return 6371.0088 * 2 * atan2(sqrt($a), sqrt(1 - $a));
	}

	/**
	 * @param string $language
	 * @return string
	 *
	 */
	protected function sanitizeLanguage($language) {
		$language = trim((string) $language);
		return preg_match('/^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{2,8})?$/', $language) ? $language : '';
	}

	/**
	 * @param string $message
	 *
	 */
	protected function logError($message) {
		if($this->log) $this->wire()->log->save(self::logName, $message);
	}

	/*** HOOKS **************************************************************************/

	/**
	 * Update the managed database once a new month is published
	 *
	 * @param HookEvent $event
	 *
	 */
	public function hookLazyCron(HookEvent $event) {
		$month = date('Y-m');
		$file = $this->getDatabaseFile();
		if(is_file($file) && $this->wire()->cache->getFor($this, 'db-month') === $month) return;
		// the previous month is only used when there is no database yet
		$this->updateDatabase(!is_file($file));
	}

	/**
	 * Alias of version 1.x: $page->GeoInfoIP($ip)
	 *
	 * Returns an object with the property names of geoPlugin. Fields that the local
	 * database does not provide (area code, DMA code, currency) are null.
	 *
	 * @param HookEvent $event
	 * @deprecated Use $modules->get('GeoInfo')->ip()
	 *
	 */
	public function hookGeoInfoIP(HookEvent $event) {
		$ip = $event->arguments(0);
		$geo = $this->ip($ip ? (string) $ip : null);
		$event->return = (object) array(
			'geoplugin_request' => $geo ? $geo->ip : (string) ($ip ? $ip : $this->getVisitorIP()),
			'geoplugin_status' => $geo ? 200 : 404,
			'geoplugin_credit' => $geo ? $geo->credit : '',
			'geoplugin_city' => $geo ? $geo->city : null,
			'geoplugin_region' => $geo ? $geo->region : null,
			'geoplugin_regionCode' => $geo ? $geo->regionCode : null,
			'geoplugin_regionName' => $geo ? $geo->region : null,
			'geoplugin_areaCode' => null,
			'geoplugin_dmaCode' => null,
			'geoplugin_countryCode' => $geo ? $geo->countryCode : null,
			'geoplugin_countryName' => $geo ? $geo->countryName : null,
			'geoplugin_inEU' => $geo ? (int) $geo->inEU : 0,
			'geoplugin_continentCode' => $geo ? $geo->continentCode : null,
			'geoplugin_continentName' => $geo ? $geo->continentName : null,
			'geoplugin_latitude' => $geo ? $geo->latitude : null,
			'geoplugin_longitude' => $geo ? $geo->longitude : null,
			'geoplugin_timezone' => $geo ? $geo->timezone : null,
			'geoplugin_currencyCode' => null,
			'geoplugin_currencySymbol' => null,
			'geoplugin_currencySymbol_UTF8' => null,
			'geoplugin_currencyConverter' => null,
		);
	}

	/**
	 * Alias of version 1.x: $page->GeoInfoLatLong($lat, $long)
	 *
	 * Returns an object with the property names of geoPlugin, or null for invalid coordinates.
	 *
	 * @param HookEvent $event
	 * @deprecated Use $modules->get('GeoInfo')->place()
	 *
	 */
	public function hookGeoInfoLatLong(HookEvent $event) {
		$place = $this->place($event->arguments(0), $event->arguments(1));
		if(!$place) return;
		$regionCode = (string) $place->regionCode;
		$event->return = (object) array(
			'geoplugin_place' => $place->place,
			'geoplugin_countryCode' => $place->countryCode,
			'geoplugin_region' => $place->region,
			'geoplugin_regionAbbreviated' => strpos($regionCode, '-') ? substr($regionCode, strpos($regionCode, '-') + 1) : null,
			'geoplugin_latitude' => $place->latitude,
			'geoplugin_longitude' => $place->longitude,
			'geoplugin_distanceMiles' => $place->distanceMiles,
			'geoplugin_distanceKilometers' => $place->distanceKilometers,
		);
	}

	/*** ADMIN **************************************************************************/

	/**
	 * Module configuration
	 *
	 * @param InputfieldWrapper $inputfields
	 *
	 */
	public function getModuleConfigInputfields(InputfieldWrapper $inputfields) {
		$modules = $this->wire()->modules;
		$input = $this->wire()->input;

		if($input->post('_geoinfo_download')) {
			if($this->updateDatabase()) {
				$this->message($this->_('The database has been downloaded.'));
			} else {
				$this->error($this->_('The database could not be downloaded, see Setup > Logs > geoinfo.'));
			}
		}

		/** @var InputfieldFieldset $fs */
		$fs = $modules->get('InputfieldFieldset');
		$fs->label = $this->_('IP database');
		$fs->icon = 'database';
		$inputfields->add($fs);

		/** @var InputfieldMarkup $f */
		$f = $modules->get('InputfieldMarkup');
		$f->label = $this->_('Status');
		$f->value = $this->renderDatabaseStatus();
		$fs->add($f);

		/** @var InputfieldText $f */
		$f = $modules->get('InputfieldText');
		$f->attr('name', 'dbFile');
		$f->label = $this->_('Custom database file');
		$f->description = $this->_('Path to an MMDB file of the type “City”, e.g. MaxMind GeoLite2 City. Relative paths start at the ProcessWire root directory. Leave blank to use the free DB-IP City Lite database, which the module downloads and updates itself.');
		$f->attr('value', $this->dbFile);
		$f->attr('placeholder', 'site/assets/GeoInfo/GeoLite2-City.mmdb');
		$f->collapsed = Inputfield::collapsedBlank;
		$fs->add($f);

		/** @var InputfieldCheckbox $f */
		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', 'autoUpdate');
		$f->label = $this->_('Update automatically');
		$f->label2 = $this->_('Download the new DB-IP City Lite database every month');
		$f->notes = $this->_('Uses LazyCron, which must be installed. Not used with a custom database file.');
		if($this->autoUpdate) $f->attr('checked', 'checked');
		$f->columnWidth = 50;
		$f->showIf = 'dbFile=""';
		$fs->add($f);

		/** @var InputfieldCheckbox $f */
		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', '_geoinfo_download');
		$f->label = $this->_('Download now');
		$f->label2 = $this->_('Download the current DB-IP City Lite database when saving');
		$f->notes = $this->_('About 60 MB compressed, 130 MB unpacked. This can take a minute.');
		$f->columnWidth = 50;
		$f->showIf = 'dbFile=""';
		$fs->add($f);

		/** @var InputfieldFieldset $fs */
		$fs = $modules->get('InputfieldFieldset');
		$fs->label = $this->_('Settings');
		$fs->icon = 'sliders';
		$inputfields->add($fs);

		/** @var InputfieldText $f */
		$f = $modules->get('InputfieldText');
		$f->attr('name', 'language');
		$f->label = $this->_('Language');
		$f->description = $this->_('ISO code for city, region and country names, e.g. en or de. Falls back to English if a name is not available in this language.');
		$f->attr('value', $this->language);
		$f->columnWidth = 34;
		$fs->add($f);

		/** @var InputfieldCheckbox $f */
		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', 'trustProxy');
		$f->label = $this->_('Trust proxy headers');
		$f->label2 = $this->_('Detect the visitor IP from X-Forwarded-For');
		$f->description = $this->_('Only enable this if the site runs behind a reverse proxy or load balancer. Otherwise visitors can fake their IP address.');
		if($this->trustProxy) $f->attr('checked', 'checked');
		$f->columnWidth = 33;
		$fs->add($f);

		/** @var InputfieldCheckbox $f */
		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', 'log');
		$f->label = $this->_('Log errors');
		$f->description = $this->_('Writes errors to Setup > Logs > geoinfo.');
		if($this->log) $f->attr('checked', 'checked');
		$f->columnWidth = 33;
		$fs->add($f);
	}

	/**
	 * Render the database status for the module configuration
	 *
	 * @return string
	 *
	 */
	protected function renderDatabaseStatus() {
		$sanitizer = $this->wire()->sanitizer;
		$file = $this->getDatabaseFile();
		$rootPath = $this->wire()->config->paths->root;
		$path = $sanitizer->entities(strpos($file, $rootPath) === 0 ? substr($file, strlen($rootPath)) : $file);

		if(!is_file($file)) {
			return "<p><i class='fa fa-warning'></i> " .
				sprintf($this->_('No database found at %s. IP lookups return null until a database is available.'), "<code>$path</code>") .
				'</p>';
		}

		$reader = $this->getReader();
		if(!$reader) {
			return "<p><i class='fa fa-warning'></i> " .
				sprintf($this->_('The database %s cannot be read, see Setup > Logs > geoinfo.'), "<code>$path</code>") .
				'</p>';
		}

		$meta = $reader->metadata();
		return
			"<p><i class='fa fa-check'></i> <code>$path</code></p>" .
			'<ul>' .
				'<li>' . $this->_('Type:') . ' ' . $sanitizer->entities($meta->databaseType) . '</li>' .
				'<li>' . $this->_('Built:') . ' ' . date('Y-m-d', $meta->buildEpoch) . '</li>' .
				'<li>' . $this->_('Size:') . ' ' . wireBytesStr(filesize($file)) . '</li>' .
			'</ul>';
	}

	/**
	 * Remove the managed database on uninstall
	 *
	 */
	public function ___uninstall() {
		$files = $this->wire()->files;
		$dir = $this->getManagedDir();
		foreach(array('', '.gz', '.tmp') as $ext) {
			if(is_file($dir . self::dbName . $ext)) $files->unlink($dir . self::dbName . $ext);
		}
		if(is_dir($dir) && count(scandir($dir)) <= 2) $files->rmdir($dir); // only when empty
	}
}
