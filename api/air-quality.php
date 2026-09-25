<?php
/**
 * CityPulse - Air Quality API (Step 9)
 *
 * Fetches the latest air-quality measurements near the browser location
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
 *
 * Debugging: the HTTP calls use PHP cURL. When a request fails, the
 * JSON response carries a safe "debug" object (http_status,
 * curl_error, api_error_message, api_url_without_api_key) so the
 * reason is visible in the browser. The API key is sent ONLY in the
 * "X-API-Key" request header - it never appears in a URL, in the
 * JSON response, in HTML or in JavaScript.
 */

// Keep PHP warnings/notices out of the JSON response.
ini_set('display_errors', '0');

require_once __DIR__ . '/../config/api_config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/location.php';

// ------------------------------------------------------------
// Tunable settings - edit these values here.
// ------------------------------------------------------------

$nearby = citypulse_request_location();
$airQualityLatitude = $nearby['latitude'];
$airQualityLongitude = $nearby['longitude'];
$airQualityLocation = 'Current area';
$airQualityRadiusMeters = $nearby['radius_km'] * 1000;

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
 * Call the OpenAQ API with PHP cURL and return
 * [status => HTTP status code, body => raw response, curl_error => message].
 *
 * The API key is sent only in the "X-API-Key" request header, so the
 * URL itself is always safe to log or to show in the debug output.
 *
 * status is 0 when the request never completed (DNS, timeout, SSL, ...);
 * in that case curl_error explains why.
 */
function air_quality_fetch($url)
{
    $result = ['status' => 0, 'body' => '', 'curl_error' => ''];

    if (!function_exists('curl_init')) {
        $result['curl_error'] = 'The PHP cURL extension is not enabled in php.ini.';
        return $result;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT      => 'CityPulse local dev (XAMPP)',
        CURLOPT_HTTPHEADER     => ['X-API-Key: ' . OPENAQ_API_KEY],
    ]);

    $body = curl_exec($ch);

    $result['status'] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $result['body']   = is_string($body) ? $body : '';

    // curl_errno() is non-zero for DNS/timeout/SSL failures. cURL also
    // reports an error for some HTTP-level problems, so we store it
    // whenever it is present - it never contains the API key.
    if (curl_errno($ch) !== 0) {
        $result['curl_error'] = curl_error($ch);
    }

    curl_close($ch);

    return $result;
}

/**
 * Pull the human-readable error message out of an OpenAQ error body.
 * OpenAQ uses several shapes, all of which we handle:
 *   {"message": "Unauthorized. ..."}          (missing API key)
 *   {"detail": "Invalid credentials"}         (wrong API key)
 *   {"detail": [{"msg": "...", ...}]}       (validation error)
 * Returns '' when the body carries no such message.
 */
function air_quality_api_error_message($body)
{
    if (!is_string($body) || $body === '') {
        return '';
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        return '';
    }

    // "message", "error" and a plain string "detail".
    foreach (['message', 'error', 'detail'] as $key) {
        if (isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '') {
            return $data[$key];
        }
    }

    // Validation errors look like {"detail": [{"msg": "...", ...}]}.
    if (isset($data['detail'][0]['msg']) && is_string($data['detail'][0]['msg'])) {
        return $data['detail'][0]['msg'];
    }

    return '';
}

/**
 * Build the safe debug object returned when a request fails.
 *
 * It contains ONLY these four keys, and never the API key:
 *   - http_status             (null when no HTTP response arrived)
 *   - curl_error              (null when cURL reported no error)
 *   - api_error_message       (null when OpenAQ sent no error message)
 *   - api_url_without_api_key (the URL we called - the key is in a header)
 */
function air_quality_debug($url, $status = 0, $curlError = '', $body = '')
{
    $apiError = air_quality_api_error_message($body);

    return [
        'http_status'             => ((int) $status) > 0 ? (int) $status : null,
        'curl_error'              => (is_string($curlError) && $curlError !== '') ? $curlError : null,
        'api_error_message'       => $apiError !== '' ? $apiError : null,
        'api_url_without_api_key' => $url,
    ];
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
 * Friendly failure response.
 *
 * The message is a short, safe explanation and the optional $debug
 * object explains the failed request. Neither ever contains the API
 * key, so the JSON is safe to view in the browser.
 */
function air_quality_fail($message = 'Air quality data temporarily unavailable.', $debug = null, $noData = false)
{
    http_response_code(200); // graceful: the dashboard can still render
    header('X-CityPulse-Fetched-At: ' . gmdate('c'));
    header('Content-Type: application/json');

    $payload = [
        'success' => false,
        'source'  => 'OpenAQ',
        'message' => $message,
    ];

    if ($noData) $payload['no_data'] = true;

    if (is_array($debug)) {
        $payload['debug'] = $debug;
    }

    echo json_encode($payload, JSON_PRETTY_PRINT);
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

function air_quality_extract_measurements($station, $latestResults)
{
    $sensorParams = [];
    if (isset($station['sensors']) && is_array($station['sensors'])) {
        foreach ($station['sensors'] as $sensor) {
            $sensorId = isset($sensor['id']) ? (int) $sensor['id'] : null;
            $pname = isset($sensor['parameter']['name']) ? $sensor['parameter']['name'] : null;
            $punit = isset($sensor['parameter']['units']) ? $sensor['parameter']['units'] : null;
            if ($sensorId !== null && $pname !== null) {
                $sensorParams[$sensorId] = ['name' => $pname, 'units' => $punit];
            }
        }
    }

    $bySensor = [];
    foreach ($latestResults as $item) {
        $sensorId = isset($item['sensorsId']) ? (int) $item['sensorsId'] : null;
        if ($sensorId === null || !isset($item['value']) || !is_numeric($item['value']) || isset($bySensor[$sensorId])) {
            continue;
        }
        $bySensor[$sensorId] = (float) $item['value'];
    }

    $measurements = ['pm25' => null, 'pm10' => null, 'no2' => null, 'o3' => null];
    foreach ($bySensor as $sensorId => $value) {
        if (!isset($sensorParams[$sensorId])) continue;
        $parameter = $sensorParams[$sensorId];
        if (!array_key_exists($parameter['name'], $measurements)) continue;
        if (stripos((string) $parameter['units'], 'µg/m') === false) continue;
        $measurements[$parameter['name']] = $value;
    }
    return $measurements;
}

// ------------------------------------------------------------
// 1) Fresh stored reading? Return it without calling OpenAQ.
// ------------------------------------------------------------
$airWhere = citypulse_distance_sql($airQualityLatitude, $airQualityLongitude, $nearby['radius_km'], 'a');
$freshQuery = mysqli_query($conn, 'SELECT a.location, a.latitude, a.longitude, a.pm25, a.pm10, a.no2, a.o3, a.severity, a.recorded_at FROM air_quality_data AS a WHERE ' . $airWhere . ' ORDER BY a.recorded_at DESC LIMIT 5');

if (!$freshQuery) {
    // The air_quality_data table does not exist yet (schema not imported)
    // or the database is down - behave like a temporary outage.
    error_log('CityPulse air-quality: fresh-record query failed: ' . mysqli_error($conn));
    air_quality_fail();
}

$freshRows = [];
while ($row = mysqli_fetch_assoc($freshQuery)) $freshRows[] = $row;
$freshRow = $freshRows[0] ?? null;

if ($freshRow !== null && (time() - strtotime($freshRow['recorded_at'])) < AIR_QUALITY_MIN_INTERVAL_SECONDS) {
    $cachedStations = [];
    foreach ($freshRows as $row) {
        $cachedStations[] = [
            'id' => null,
            'name' => $row['location'],
            'latitude' => (float) $row['latitude'],
            'longitude' => (float) $row['longitude'],
            'measurements' => [
                'pm25' => $row['pm25'] !== null ? (float) $row['pm25'] : null,
                'pm10' => $row['pm10'] !== null ? (float) $row['pm10'] : null,
                'no2'  => $row['no2']  !== null ? (float) $row['no2']  : null,
                'o3'   => $row['o3']   !== null ? (float) $row['o3']   : null,
            ],
            'severity' => $row['severity'],
        ];
    }
    header('X-CityPulse-Fetched-At: ' . gmdate('c'));
    header('Content-Type: application/json');
    echo json_encode([
        'success'      => true,
        'source'       => 'OpenAQ',
        'location'     => $airQualityLocation,
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
        'stations' => $cachedStations,
        'stored'     => false, // served from the existing fresh row
    ], JSON_PRETTY_PRINT);
    exit;
}

// ------------------------------------------------------------
// 2) No fresh row - ask OpenAQ for data near the browser location.
// ------------------------------------------------------------

// 2a) Find monitoring stations near the browser location.
$locationsUrl = 'https://api.openaq.org/v3/locations'
    . '?coordinates=' . $airQualityLatitude . ',' . $airQualityLongitude
    . '&radius=' . $airQualityRadiusMeters
    . '&parameters_id=' . OPENAQ_PARAMETER_IDS
    . '&limit=25';

// The API key must be configured before we can talk to OpenAQ.
if (OPENAQ_API_KEY === '' || OPENAQ_API_KEY === 'YOUR_OPENAQ_API_KEY_HERE') {
    error_log('CityPulse air-quality: OPENAQ_API_KEY is not configured yet. Add it in config/api_config.php.');
    air_quality_fail('OpenAQ API key is missing.', air_quality_debug($locationsUrl));
}

$locationsCall = air_quality_fetch($locationsUrl);
if ($locationsCall['status'] < 200 || $locationsCall['status'] >= 300) {
    $locationsDebug = air_quality_debug($locationsUrl, $locationsCall['status'], $locationsCall['curl_error'], $locationsCall['body']);
    error_log('CityPulse air-quality: locations call failed (HTTP ' . $locationsCall['status'] . ')'
        . ' curl_error=' . $locationsCall['curl_error']
        . ' api_error_message=' . $locationsDebug['api_error_message']);
    air_quality_fail(
        $locationsCall['status'] === 0
            ? 'Could not reach the OpenAQ service.'
            : 'OpenAQ request failed (HTTP ' . $locationsCall['status'] . ').',
        $locationsDebug
    );
}

$locations = air_quality_results($locationsCall['body']);
if ($locations === null || count($locations) === 0) {
    error_log('CityPulse air-quality: no monitoring stations found near the browser location.');
    air_quality_fail(
        'No nearby AQI station found.',
        air_quality_debug($locationsUrl, $locationsCall['status'], $locationsCall['curl_error'], $locationsCall['body']),
        true
    );
}

// 2b) Pick the station nearest to the browser location (the API only sorts by id,
//     so we compare distances ourselves).
$nearest = null;
$nearestKm = PHP_FLOAT_MAX;

foreach ($locations as $loc) {
    if (!isset($loc['id'], $loc['coordinates']['latitude'], $loc['coordinates']['longitude'])) {
        continue;
    }
    $lat = (float) $loc['coordinates']['latitude'];
    $lng = (float) $loc['coordinates']['longitude'];
    $km = haversine_km($airQualityLatitude, $airQualityLongitude, $lat, $lng);
    if ($km < $nearestKm) {
        $nearestKm = $km;
        $nearest = $loc;
    }
}

if ($nearest === null) {
    error_log('CityPulse air-quality: stations found, but none with usable coordinates.');
    air_quality_fail(
        'No nearby AQI station with usable coordinates found.',
        air_quality_debug($locationsUrl, $locationsCall['status'], $locationsCall['curl_error'], $locationsCall['body']),
        true
    );
}

$stationId   = (int) $nearest['id'];
$stationName = isset($nearest['name']) ? (string) $nearest['name'] : ('Station ' . $stationId);
$stationLat  = (float) $nearest['coordinates']['latitude'];
$stationLng  = (float) $nearest['coordinates']['longitude'];

// 2c) Read the latest readings at that station.
$latestUrl = 'https://api.openaq.org/v3/locations/' . $stationId . '/latest?limit=100';

$latestCall = air_quality_fetch($latestUrl);
if ($latestCall['status'] < 200 || $latestCall['status'] >= 300) {
    $latestDebug = air_quality_debug($latestUrl, $latestCall['status'], $latestCall['curl_error'], $latestCall['body']);
    error_log('CityPulse air-quality: latest readings call failed (HTTP ' . $latestCall['status'] . ')'
        . ' curl_error=' . $latestCall['curl_error']
        . ' api_error_message=' . $latestDebug['api_error_message']);
    air_quality_fail(
        $latestCall['status'] === 0
            ? 'Could not reach the OpenAQ service.'
            : 'OpenAQ request failed (HTTP ' . $latestCall['status'] . ').',
        $latestDebug
    );
}

$latestResults = air_quality_results($latestCall['body']);
if ($latestResults === null || count($latestResults) === 0) {
    error_log('CityPulse air-quality: station ' . $stationId . ' returned no latest readings.');
    air_quality_fail(
        'OpenAQ returned no latest measurements for station ' . $stationId . '.',
        air_quality_debug($latestUrl, $latestCall['status'], $latestCall['curl_error'], $latestCall['body'])
    );
}

$measurements = air_quality_extract_measurements($nearest, $latestResults);

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
    air_quality_fail(
        'OpenAQ returned no usable measurements near the current location.',
        air_quality_debug($latestUrl, $latestCall['status'], $latestCall['curl_error'], $latestCall['body'])
    );
}

$nearbyStations = [[
    'id' => $stationId,
    'name' => $stationName,
    'latitude' => $stationLat,
    'longitude' => $stationLng,
    'measurements' => $measurements,
    'severity' => air_quality_severity($measurements),
]];

// Read a small set of additional real stations so the dashboard can show
// separate nearby observations without inventing values or coordinates.
foreach ($locations as $candidate) {
    if (count($nearbyStations) >= 5 || !isset($candidate['id'], $candidate['coordinates']['latitude'], $candidate['coordinates']['longitude'])) {
        break;
    }
    $candidateId = (int) $candidate['id'];
    if ($candidateId === $stationId) continue;

    $candidateUrl = 'https://api.openaq.org/v3/locations/' . $candidateId . '/latest?limit=100';
    $candidateCall = air_quality_fetch($candidateUrl);
    if ($candidateCall['status'] < 200 || $candidateCall['status'] >= 300) continue;
    $candidateResults = air_quality_results($candidateCall['body']);
    if ($candidateResults === null || count($candidateResults) === 0) continue;
    $candidateMeasurements = air_quality_extract_measurements($candidate, $candidateResults);
    $candidateHasAny = false;
    foreach ($candidateMeasurements as $value) {
        if ($value !== null) {
            $candidateHasAny = true;
            break;
        }
    }
    if (!$candidateHasAny) continue;

    $nearbyStations[] = [
        'id' => $candidateId,
        'name' => isset($candidate['name']) ? (string) $candidate['name'] : ('Station ' . $candidateId),
        'latitude' => (float) $candidate['coordinates']['latitude'],
        'longitude' => (float) $candidate['coordinates']['longitude'],
        'measurements' => $candidateMeasurements,
        'severity' => air_quality_severity($candidateMeasurements),
    ];
}

$summaryMeasurements = $measurements;
foreach (['pm25', 'pm10', 'no2', 'o3'] as $pollutant) {
    $values = [];
    foreach ($nearbyStations as $nearbyStation) {
        $value = $nearbyStation['measurements'][$pollutant];
        if ($value !== null) $values[] = $value;
    }
    if (count($values) > 1) $summaryMeasurements[$pollutant] = array_sum($values) / count($values);
}

$severity   = air_quality_severity($measurements);
$recordedAt = date('Y-m-d H:i:s');

// ------------------------------------------------------------
// 3) Store the reading (only when the previous row is old).
// ------------------------------------------------------------
$stored = false;

// (The fresh-row check above already ran, so an insert is normally
//  expected here - but guard against any race with a quick re-check.)
$checkRow = mysqli_query($conn, 'SELECT MAX(a.recorded_at) AS latest FROM air_quality_data AS a WHERE ' . $airWhere);
if ($checkRow) {
    $latestWhen = mysqli_fetch_assoc($checkRow)['latest'];
    if ($latestWhen === null || (time() - strtotime($latestWhen)) >= AIR_QUALITY_MIN_INTERVAL_SECONDS) {
        $stmt = mysqli_prepare($conn, 'INSERT INTO air_quality_data (location, latitude, longitude, pm25, pm10, no2, o3, severity, recorded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');

        foreach ($nearbyStations as $nearbyStation) {
            $stationMeasurements = $nearbyStation['measurements'];
            $loc = $nearbyStation['name'];
            $stationLatValue = $nearbyStation['latitude'];
            $stationLngValue = $nearbyStation['longitude'];
            $stationPm25 = $stationMeasurements['pm25'];
            $stationPm10 = $stationMeasurements['pm10'];
            $stationNo2 = $stationMeasurements['no2'];
            $stationO3 = $stationMeasurements['o3'];
            $stationSeverity = $nearbyStation['severity'];

            mysqli_stmt_bind_param($stmt, 'sddddddss', $loc, $stationLatValue, $stationLngValue, $stationPm25, $stationPm10, $stationNo2, $stationO3, $stationSeverity, $recordedAt);
            if (mysqli_stmt_execute($stmt)) {
                $stored = true;
            } else {
                error_log('CityPulse air-quality insert failed: ' . mysqli_error($conn));
            }
        }
        mysqli_stmt_close($stmt);
    }
}

// ------------------------------------------------------------
// 4) Return the air-quality data as JSON.
// ------------------------------------------------------------
header('X-CityPulse-Fetched-At: ' . gmdate('c'));
header('Content-Type: application/json');
echo json_encode([
    'success'      => true,
    'source'       => 'OpenAQ',
    'location'     => $airQualityLocation,
    'latitude'     => $stationLat,
    'longitude'    => $stationLng,
    'station'      => $stationName,
    'measurements' => $summaryMeasurements,
    'stations'     => $nearbyStations,
    'severity'     => $severity,
    'recorded_at'  => $recordedAt,
    'stored'       => $stored,
], JSON_PRETTY_PRINT);