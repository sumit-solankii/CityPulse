<?php
/**
 * CityPulse - Air Quality API (Step 9)
 *
 * Fetches the latest air-quality measurements near Jaipur, Rajasthan
 * from the OpenAQ API v3 and returns a clean CityPulse structure.
 *
 * URL: /CityPulse/api/air-quality.php
 *
 * OpenAQ requires a free API key (sent via the "X-API-Key" header).
 * The key lives ONLY in config/api_config.php - never in JavaScript
 * or HTML. Free tier rate limit: 60 requests / minute, 2000 / hour
 * (docs.openaq.org/using-the-api/rate-limits).
 *
 * Data flow:
 *   1) If a fresh reading was already stored (< AIR_QUALITY_MIN_INTERVAL_SECONDS),
 *      return it from MySQL without calling OpenAQ. This keeps the
 *      dashboard's 30-second refresh from hammering the rate limit.
 *   2) Otherwise ask OpenAQ which monitoring stations sit near Jaipur,
 *      then read the nearest station's latest readings.
 *   3) Map sensor readings to PM2.5 / PM10 / NO2 / O3, compute a simple
 *      severity, store a row in air_quality_data, and return JSON.
 *
 * Missing pollutants at a station are NOT an error - the missing
 * entry is simply returned as null and the rest of the data is kept.
 */

// Keep PHP warnings/notices out of the JSON response.
ini_set('display_errors', '0');

require_once __DIR__ . '/../config/api_config.php';
require_once __DIR__ . '/../config/database.php';

// ------------------------------------------------------------
// Tunable settings - edit these values here.
// ------------------------------------------------------------

// Monitored area (Jaipur, Rajasthan is the initial target area).
define('AIR_QUALITY_LOCATION', 'Jaipur');
define('AIR_QUALITY_LATITUDE', 26.9124);
define('AIR_QUALITY_LONGITUDE', 75.7873);

// Search radius in meters around Jaipur for monitoring stations
// (OpenAQ maximum is 25000).
define('AIR_QUALITY_RADIUS_METERS', 25000);

// How long a stored reading stays "fresh" (seconds). 900 = 15 min.
// While a reading is fresh the endpoint returns it from MySQL and
// does NOT call OpenAQ again.
define('AIR_QUALITY_MIN_INTERVAL_SECONDS', 900);

// OpenAQ v3 parameter IDs: pm10=1, pm25=2, no2=7, o3=10.
define('OPENAQ_PARAMETER_IDS', '1,2,7,10');

// ------------------------------------------------------------
// Air quality severity thresholds (µg/m³) - transparent rules.
//
// A pollutant is MODERATE when it is above its "moderate" value
// and HIGH when above its "high" value. The worst pollutant
// decides the overall severity. Simple rules - no machine learning.
// Change these numbers any time; the logic reads them directly.
// ------------------------------------------------------------
$AQ_THRESHOLDS = [
    'pm25' => ['moderate' => 35.5, 'high' => 55.5],
    'pm10' => ['moderate' => 150.0, 'high' => 250.0],
    'no2'  => ['moderate' => 100.0, 'high' => 200.0],
    'o3'   => ['moderate' => 160.0, 'high' => 200.0],
];

// ------------------------------------------------------------
// Helpers
// ------------------------------------------------------------

/**
 * Call the OpenAQ API with the X-API-Key header and return
 * [status => HTTP status code, body => raw response].
 */
function air_quality_fetch($url)
{
    $context = stream_context_create([
        'http' => [
            'timeout'      => 12,
            'ignore_errors' => true,
            'user_agent'   => 'CityPulse local dev (XAMPP)',
            'header'       => 'X-API-Key: ' . OPENAQ_API_KEY . "\r\n",
        ],
    ]);

    $body = @file_get_contents($url, false, $context);

    // file_get_contents fills $http_response_header with the HTTP
    // response lines when the URL uses http/https.
    $status = 0;
    if (is_array($http_response_header)) {
        foreach ($http_response_header as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
                break;
            }
        }
    }

    return ['status' => $status, 'body' => $body];
}

/**
 * Straight-line distance in km between two coordinates (Haversine).
 */
function haversine_km($latA, $lngA, $latB, $lngB)
{
    $earthKm = 6371.0;
    $dLat = deg2rad($latB - $latA);
    $dLng = deg2rad($lngB - $lngA);
    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($latA)) * cos(deg2rad($latB)) * sin($dLng / 2) ** 2;
    return $earthKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/**
 * Simple severity from the available measurements (µg/m³).
 * Returns NORMAL / MODERATE / HIGH. Missing values are ignored.
 */
function air_quality_severity($measurements)
{
    global $AQ_THRESHOLDS;

    $severity = 'NORMAL';
    foreach ($AQ_THRESHOLDS as $pollutant => $thresholds) {
        $value = isset($measurements[$pollutant]) ? $measurements[$pollutant] : null;
        if ($value === null) {
            continue;
        }
        if ($value > $thresholds['high']) {
            return 'HIGH';
        }
        if ($value > $thresholds['moderate']) {
            $severity = 'MODERATE';
        }
    }
    return $severity;
}

/**
 * Friendly failure response. Kept generic on purpose - we never
 * expose the API key or OpenAQ's raw error details.
 */
function air_quality_fail()
{
    http_response_code(200); // graceful: the dashboard can still render
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'source'  => 'OpenAQ',
        'message' => 'Air quality data temporarily unavailable.',
    ], JSON_PRETTY_PRINT);
    exit;
}

/**
 * Parse an OpenAQ response and return its "results" array,
 * or null when the response is not usable.
 */
function air_quality_results($response)
{
    if ($response === false || $response === '') {
        return null;
    }
    $data = json_decode($response, true);
    if (!is_array($data) || !isset($data['results']) || !is_array($data['results'])) {
        return null;
    }
    return $data['results'];
}

// ------------------------------------------------------------
// 1) Fresh stored reading? Return it without calling OpenAQ.
// ------------------------------------------------------------
$freshQuery = mysqli_query($conn, 'SELECT location, latitude, longitude, pm25, pm10, no2, o3, severity, recorded_at FROM air_quality_data ORDER BY recorded_at DESC LIMIT 1');

if (!$freshQuery) {
    // The air_quality_data table does not exist yet (schema not imported)
    // or the database is down - behave like a temporary outage.
    error_log('CityPulse air-quality: fresh-record query failed: ' . mysqli_error($conn));
    air_quality_fail();
}

$freshRow = mysqli_fetch_assoc($freshQuery);

if ($freshRow !== null && (time() - strtotime($freshRow['recorded_at'])) < AIR_QUALITY_MIN_INTERVAL_SECONDS) {
    header('Content-Type: application/json');
    echo json_encode([
        'success'      => true,
        'source'       => 'OpenAQ',
        'location'     => AIR_QUALITY_LOCATION,
        'latitude'     => (float) $freshRow['latitude'],
        'longitude'    => (float) $freshRow['longitude'],
        'measurements' => [
            'pm25' => $freshRow['pm25'] !== null ? (float) $freshRow['pm25'] : null,
            'pm10' => $freshRow['pm10'] !== null ? (float) $freshRow['pm10'] : null,
            'no2'  => $freshRow['no2']  !== null ? (float) $freshRow['no2']  : null,
            'o3'   => $freshRow['o3']   !== null ? (float) $freshRow['o3']   : null,
        ],
        'severity'   => $freshRow['severity'],
        'recorded_at' => $freshRow['recorded_at'],
        'stored'     => false, // served from the existing fresh row
    ], JSON_PRETTY_PRINT);
    exit;
}

// ------------------------------------------------------------
// 2) No fresh row - ask OpenAQ for data near Jaipur.
// ------------------------------------------------------------

// The API key must be configured before we can talk to OpenAQ.
if (OPENAQ_API_KEY === '' || OPENAQ_API_KEY === 'YOUR_OPENAQ_API_KEY_HERE') {
    error_log('CityPulse air-quality: OPENAQ_API_KEY is not configured yet. Add it in config/api_config.php.');
    air_quality_fail();
}

// 2a) Find monitoring stations near Jaipur that measure our pollutants.
$locationsUrl = 'https://api.openaq.org/v3/locations'
    . '?coordinates=' . AIR_QUALITY_LATITUDE . ',' . AIR_QUALITY_LONGITUDE
    . '&radius=' . AIR_QUALITY_RADIUS_METERS
    . '&parameters_id=' . OPENAQ_PARAMETER_IDS
    . '&limit=25';

$locationsCall = air_quality_fetch($locationsUrl);
if ($locationsCall['status'] < 200 || $locationsCall['status'] >= 300) {
    error_log('CityPulse air-quality: locations call failed (HTTP ' . $locationsCall['status'] . ').');
    air_quality_fail();
}

$locations = air_quality_results($locationsCall['body']);
if ($locations === null || count($locations) === 0) {
    error_log('CityPulse air-quality: no monitoring stations found near Jaipur.');
    air_quality_fail();
}

// 2b) Pick the station nearest to Jaipur (the API only sorts by id,
//     so we compare distances ourselves).
$nearest = null;
$nearestKm = PHP_FLOAT_MAX;

foreach ($locations as $loc) {
    if (!isset($loc['id'], $loc['coordinates']['latitude'], $loc['coordinates']['longitude'])) {
        continue;
    }
    $lat = (float) $loc['coordinates']['latitude'];
    $lng = (float) $loc['coordinates']['longitude'];
    $km = haversine_km(AIR_QUALITY_LATITUDE, AIR_QUALITY_LONGITUDE, $lat, $lng);
    if ($km < $nearestKm) {
        $nearestKm = $km;
        $nearest = $loc;
    }
}

if ($nearest === null) {
    error_log('CityPulse air-quality: stations found, but none with usable coordinates.');
    air_quality_fail();
}

$stationId   = (int) $nearest['id'];
$stationName = isset($nearest['name']) ? (string) $nearest['name'] : ('Station ' . $stationId);
$stationLat  = (float) $nearest['coordinates']['latitude'];
$stationLng  = (float) $nearest['coordinates']['longitude'];

// Which pollutant does each sensor at this station measure?
// sensorsId -> ['name' => parameter name, 'units' => unit string]
$sensorParams = [];
if (isset($nearest['sensors']) && is_array($nearest['sensors'])) {
    foreach ($nearest['sensors'] as $sensor) {
        $sensorId = isset($sensor['id']) ? (int) $sensor['id'] : null;
        $pname    = isset($sensor['parameter']['name']) ? $sensor['parameter']['name'] : null;
        $punit    = isset($sensor['parameter']['units']) ? $sensor['parameter']['units'] : null;
        if ($sensorId !== null && $pname !== null) {
            $sensorParams[$sensorId] = ['name' => $pname, 'units' => $punit];
        }
    }
}

// 2c) Read the latest readings at that station.
$latestUrl = 'https://api.openaq.org/v3/locations/' . $stationId . '/latest?limit=100';

$latestCall = air_quality_fetch($latestUrl);
if ($latestCall['status'] < 200 || $latestCall['status'] >= 300) {
    error_log('CityPulse air-quality: latest readings call failed (HTTP ' . $latestCall['status'] . ').');
    air_quality_fail();
}

$latestResults = air_quality_results($latestCall['body']);
if ($latestResults === null || count($latestResults) === 0) {
    error_log('CityPulse air-quality: station ' . $stationId . ' returned no latest readings.');
    air_quality_fail();
}

// sensorId -> latest value (keep the first occurrence for each sensor).
$bySensor = [];
foreach ($latestResults as $item) {
    $sensorId = isset($item['sensorsId']) ? (int) $item['sensorsId'] : null;
    if ($sensorId === null || !isset($item['value']) || !is_numeric($item['value'])) {
        continue;
    }
    if (isset($bySensor[$sensorId])) {
        continue;
    }
    $bySensor[$sensorId] = (float) $item['value'];
}

// 2d) Collect our four pollutants. Only mass-concentration values
//     (µg/m³) are used so the thresholds above stay meaningful -
//     ppm readings for NO2/O3 from some providers are skipped
//     (that pollutant simply shows as null, not an error).
$measurements = ['pm25' => null, 'pm10' => null, 'no2' => null, 'o3' => null];
foreach ($bySensor as $sensorId => $value) {
    if (!isset($sensorParams[$sensorId])) {
        continue;
    }
    $pname = $sensorParams[$sensorId]['name'];
    $punit = $sensorParams[$sensorId]['units'];
    if (!array_key_exists($pname, $measurements)) {
        continue; // not one of our pollutants
    }
    if (stripos((string) $punit, 'µg/m') === false) {
        continue; // not a µg/m³ value
    }
    $measurements[$pname] = $value;
}

// If the station reported nothing usable, treat it as an outage
// (we still return healthy partial data when at least one is present).
$hasAny = false;
foreach ($measurements as $value) {
    if ($value !== null) {
        $hasAny = true;
        break;
    }
}
if (!$hasAny) {
    error_log('CityPulse air-quality: station ' . $stationId . ' had no usable µg/m³ values.');
    air_quality_fail();
}

$severity   = air_quality_severity($measurements);
$recordedAt = date('Y-m-d H:i:s');

// ------------------------------------------------------------
// 3) Store the reading (only when the previous row is old).
// ------------------------------------------------------------
$stored = false;

$pm25 = $measurements['pm25'];
$pm10 = $measurements['pm10'];
$no2  = $measurements['no2'];
$o3   = $measurements['o3'];

// (The fresh-row check above already ran, so an insert is normally
//  expected here - but guard against any race with a quick re-check.)
$checkRow = mysqli_query($conn, 'SELECT MAX(recorded_at) AS latest FROM air_quality_data');
if ($checkRow) {
    $latestWhen = mysqli_fetch_assoc($checkRow)['latest'];
    if ($latestWhen === null || (time() - strtotime($latestWhen)) >= AIR_QUALITY_MIN_INTERVAL_SECONDS) {
        $stmt = mysqli_prepare($conn, 'INSERT INTO air_quality_data (location, latitude, longitude, pm25, pm10, no2, o3, severity, recorded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');

        // mysqli_stmt_bind_param needs plain variables (it binds by
        // reference); null values are stored as NULL in MySQL.
        $loc = AIR_QUALITY_LOCATION;

        mysqli_stmt_bind_param($stmt, 'sddddddss', $loc, $stationLat, $stationLng, $pm25, $pm10, $no2, $o3, $severity, $recordedAt);

        if (mysqli_stmt_execute($stmt)) {
            $stored = true;
        } else {
            // The OpenAQ fetch succeeded, but saving to MySQL failed.
            error_log('CityPulse air-quality insert failed: ' . mysqli_error($conn));
        }
        mysqli_stmt_close($stmt);
    }
}

// ------------------------------------------------------------
// 4) Return the air-quality data as JSON.
// ------------------------------------------------------------
header('Content-Type: application/json');
echo json_encode([
    'success'      => true,
    'source'       => 'OpenAQ',
    'location'     => AIR_QUALITY_LOCATION,
    'latitude'     => $stationLat,
    'longitude'    => $stationLng,
    'station'      => $stationName,
    'measurements' => $measurements,
    'severity'     => $severity,
    'recorded_at'  => $recordedAt,
    'stored'       => $stored,
], JSON_PRETTY_PRINT);