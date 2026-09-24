<?php
/**
 * CityPulse - Weather API (Step 4)
 *
 * Fetches the current weather for Jaipur, Rajasthan from the free
 * Open-Meteo API, maps it into a simple CityPulse structure, and
 * stores a record in the weather_data table.
 *
 * URL: /CityPulse/api/weather.php
 *
 * Open-Meteo needs no API key and is free for non-commercial use:
 *   https://open-meteo.com/
 *
 * Duplicate protection:
 *   A new row is stored only when the previous weather record is
 *   older than WEATHER_MIN_INTERVAL_SECONDS (10 minutes), so
 *   refreshing this endpoint does not spam the database.
 */

require_once __DIR__ . '/../config/database.php';

// How often we keep a weather record (in seconds). 600 = 10 minutes.
define('WEATHER_MIN_INTERVAL_SECONDS', 600);

// Jaipur, Rajasthan - initial location.
define('WEATHER_LOCATION', 'Jaipur');
define('WEATHER_LATITUDE', 26.9124);
define('WEATHER_LONGITUDE', 75.7873);

/**
 * Convert an Open-Meteo WMO weather code into a simple
 * human-readable condition. Codes: https://open-meteo.com/en/docs
 */
function weather_condition($code)
{
    switch ((int) $code) {
        case 0:                 return 'Clear';
        case 1:
        case 2:                 return 'Partly Cloudy';
        case 3:                 return 'Cloudy';
        case 45:
        case 48:                return 'Foggy';
        case 51:
        case 53:
        case 55:
        case 61:                return 'Light Rain';
        case 63:
        case 66:
        case 67:
        case 80:
        case 81:                return 'Rain';
        case 65:
        case 82:                return 'Heavy Rain';
        case 71:
        case 73:
        case 75:
        case 77:
        case 85:
        case 86:                return 'Snow';
        case 95:
        case 96:
        case 99:                return 'Thunderstorm';
        default:                return 'Unknown';
    }
}

/**
 * A basic weather severity based only on the available values.
 * Simple rules only - no machine learning.
 */
function weather_severity($code, $temperature, $rain, $windSpeed)
{
    // Start with normal conditions.
    $severity = 'NORMAL';

    // Thunderstorms are always high risk.
    if ($code >= 95) {
        return 'HIGH';
    }

    // Heavy rain is always high risk.
    if ($code == 65 || $code == 82) {
        return 'HIGH';
    }
    if ($rain >= 30) {
        return 'HIGH';
    }

    // Snow is at least moderate.
    if (($code >= 71 && $code <= 77) || $code == 85 || $code == 86) {
        $severity = 'MODERATE';
    }

    // Heavy rain (moderate threshold).
    if ($rain >= 10) {
        $severity = 'MODERATE';
    }

    // High wind speeds.
    if ($windSpeed >= 60) {
        return 'HIGH';
    }
    if ($windSpeed >= 40) {
        $severity = 'MODERATE';
    }

    // Extreme temperatures.
    if ($temperature >= 45 || $temperature <= 0) {
        return 'HIGH';
    }
    if ($temperature >= 40 || $temperature <= 5) {
        $severity = 'MODERATE';
    }

    return $severity;
}

// ------------------------------------------------------------
// 1) Call the Open-Meteo API.
// ------------------------------------------------------------
$url = 'https://api.open-meteo.com/v1/forecast'
    . '?latitude=' . WEATHER_LATITUDE
    . '&longitude=' . WEATHER_LONGITUDE
    . '&current=temperature_2m,relative_humidity_2m,precipitation,rain,wind_speed_10m,weather_code'
    . '&timezone=Asia%2FKolkata';

// Add a timeout so a slow weather service never hangs the page.
$context = stream_context_create([
    'http' => [
        'timeout' => 10,
        'ignore_errors' => true,
        'user_agent' => 'CityPulse local dev (XAMPP)',
    ],
]);

$response = @file_get_contents($url, false, $context);

// Validate the response. On any failure we return a friendly JSON
// error and log details for developers (no secrets are involved).
if ($response === false) {
    error_log('CityPulse weather: Open-Meteo request failed.');
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Weather service is currently unavailable. Please try again later.']);
    exit;
}

$data = json_decode($response, true);

if (!is_array($data) || !isset($data['current'])) {
    error_log('CityPulse weather: unexpected Open-Meteo response: ' . substr($response, 0, 300));
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Weather service returned invalid data. Please try again later.']);
    exit;
}

$current = $data['current'];

// ------------------------------------------------------------
// 2) Build the simple CityPulse weather structure.
// ------------------------------------------------------------
$temperature  = (float) $current['temperature_2m'];
$humidity     = isset($current['relative_humidity_2m']) ? (float) $current['relative_humidity_2m'] : null;
$rainfall     = (float) $current['rain'];
$windSpeed    = (float) $current['wind_speed_10m'];
$weatherCode  = (int) $current['weather_code'];

$result = [
    'location'          => WEATHER_LOCATION,
    'latitude'          => WEATHER_LATITUDE,
    'longitude'         => WEATHER_LONGITUDE,
    'temperature'       => $temperature,
    'relative_humidity' => $humidity,
    'rainfall'          => $rainfall,
    'wind_speed'        => $windSpeed,
    'weather_condition' => weather_condition($weatherCode),
    'severity'          => weather_severity($weatherCode, $temperature, $rainfall, $windSpeed),
    'recorded_at'       => date('Y-m-d H:i:s'),
    'stored'            => false,
];

// ------------------------------------------------------------
// 3) Store the weather record only if the previous one is old.
// ------------------------------------------------------------
$latestRow = mysqli_query($conn, 'SELECT MAX(recorded_at) AS latest FROM weather_data');

if (!$latestRow) {
    error_log('CityPulse weather: latest-record query failed: ' . mysqli_error($conn));
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Could not check the weather database. Please try again later.']);
    exit;
}

$latest = mysqli_fetch_assoc($latestRow)['latest'];

if ($latest !== null && (time() - strtotime($latest)) < WEATHER_MIN_INTERVAL_SECONDS) {
    // The previous record is still fresh - skip the insert.
    $result['stored'] = false;
} else {
    $stmt = mysqli_prepare($conn, 'INSERT INTO weather_data (location, latitude, longitude, temperature, rainfall, wind_speed, weather_condition, severity, recorded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');

    // mysqli_stmt_bind_param needs plain variables (it binds by reference),
    // so copy the values into local variables first.
    $loc       = WEATHER_LOCATION;
    $lat       = WEATHER_LATITUDE;
    $lng       = WEATHER_LONGITUDE;
    $condition = $result['weather_condition'];
    $severity  = $result['severity'];
    $recorded  = $result['recorded_at'];

    mysqli_stmt_bind_param($stmt, 'sdddddsss', $loc, $lat, $lng, $temperature, $rainfall, $windSpeed, $condition, $severity, $recorded);

    if (mysqli_stmt_execute($stmt)) {
        $result['stored'] = true;
    } else {
        // The weather fetch succeeded, but saving to MySQL failed.
        error_log('CityPulse weather insert failed: ' . mysqli_error($conn));
    }
    mysqli_stmt_close($stmt);
}

// ------------------------------------------------------------
// 4) Return the weather as JSON.
// ------------------------------------------------------------
header('Content-Type: application/json');
echo json_encode($result, JSON_PRETTY_PRINT);