<?php
/**
 * CityPulse - Normalized Data API (Step 5)
 *
 * Fetches the latest records from weather_data, traffic_data,
 * incidents and air_quality_data, and combines them into ONE common
 * structure so every civic feed looks the same:
 *
 *   {
 *     "source":      "weather" | "traffic" | "incident" | "air_quality",
 *     "event_type":  same as source,
 *     "location":    area name,
 *     "latitude":    ...,
 *     "longitude":   ...,
 *     "value":       temperature / delay_minutes / incident_type / pm25,
 *     "severity":    NORMAL | MODERATE | HIGH,
 *     "timestamp":   "Y-m-d H:i:s"
 *   }
 *
 * Notes:
 *   - Every source uses the same field name "timestamp" (the raw
 *     recorded_at value is copied into it).
 *   - Severity values are normalized to NORMAL / MODERATE / HIGH.
 *   - Air quality uses PM2.5 as its primary "value" (the detailed
 *     PM10 / NO2 / O3 readings stay in api/air-quality.php and in
 *     the air_quality_data table).
 *   - No new database table: normalization happens on the fly, so
 *     data is never duplicated.
 *
 * URL: /CityPulse/api/normalized-data.php
 */

// Keep PHP warnings/notices out of the JSON response.
ini_set('display_errors', '0');

require_once __DIR__ . '/../config/database.php';

/**
 * Convert any stored severity wording into the standard set:
 * NORMAL, MODERATE or HIGH.
 */
function normalize_severity($value)
{
    $key = strtolower(trim((string) $value));
    switch ($key) {
        case 'moderate': return 'MODERATE';
        case 'high':
        case 'severe':
        case 'critical': return 'HIGH';
        case 'low':
        case 'normal':
        default:        return 'NORMAL';
    }
}

$records = [];
$weatherCount = 0;
$trafficCount = 0;
$incidentCount = 0;
$airQualityCount = 0;
$latestTimestamp = null;

// ------------------------------------------------------------
// 1) Weather -> value = temperature
// ------------------------------------------------------------
$result = mysqli_query($conn, 'SELECT location, latitude, longitude, temperature, severity, recorded_at FROM weather_data ORDER BY recorded_at DESC LIMIT 100');

if (!$result) {
    error_log('CityPulse normalized-data weather query failed: ' . mysqli_error($conn));
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Could not load normalized data. Please try again later.']);
    exit;
}

while ($row = mysqli_fetch_assoc($result)) {
    $records[] = [
        'source'     => 'weather',
        'event_type' => 'weather',
        'location'   => $row['location'],
        'latitude'   => (float) $row['latitude'],
        'longitude'  => (float) $row['longitude'],
        'value'      => (float) $row['temperature'],
        'severity'   => normalize_severity($row['severity']),
        'timestamp'  => $row['recorded_at'],
    ];
    $weatherCount++;
    if ($latestTimestamp === null || $row['recorded_at'] > $latestTimestamp) {
        $latestTimestamp = $row['recorded_at'];
    }
}

// ------------------------------------------------------------
// 2) Traffic -> value = delay_minutes
// ------------------------------------------------------------
$result = mysqli_query($conn, 'SELECT location, latitude, longitude, delay_minutes, severity, recorded_at FROM traffic_data ORDER BY recorded_at DESC LIMIT 100');

if (!$result) {
    error_log('CityPulse normalized-data traffic query failed: ' . mysqli_error($conn));
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Could not load normalized data. Please try again later.']);
    exit;
}

while ($row = mysqli_fetch_assoc($result)) {
    $records[] = [
        'source'     => 'traffic',
        'event_type' => 'traffic',
        'location'   => $row['location'],
        'latitude'   => (float) $row['latitude'],
        'longitude'  => (float) $row['longitude'],
        'value'      => (int) $row['delay_minutes'],
        'severity'   => normalize_severity($row['severity']),
        'timestamp'  => $row['recorded_at'],
    ];
    $trafficCount++;
    if ($latestTimestamp === null || $row['recorded_at'] > $latestTimestamp) {
        $latestTimestamp = $row['recorded_at'];
    }
}

// ------------------------------------------------------------
// 3) Incidents -> value = incident_type
// ------------------------------------------------------------
$result = mysqli_query($conn, 'SELECT location, latitude, longitude, incident_type, severity, recorded_at FROM incidents ORDER BY recorded_at DESC LIMIT 100');

if (!$result) {
    error_log('CityPulse normalized-data incidents query failed: ' . mysqli_error($conn));
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Could not load normalized data. Please try again later.']);
    exit;
}

while ($row = mysqli_fetch_assoc($result)) {
    $records[] = [
        'source'     => 'incident',
        'event_type' => 'incident',
        'location'   => $row['location'],
        'latitude'   => (float) $row['latitude'],
        'longitude'  => (float) $row['longitude'],
        'value'      => $row['incident_type'],
        'severity'   => normalize_severity($row['severity']),
        'timestamp'  => $row['recorded_at'],
    ];
    $incidentCount++;
    if ($latestTimestamp === null || $row['recorded_at'] > $latestTimestamp) {
        $latestTimestamp = $row['recorded_at'];
    }
}

// ------------------------------------------------------------
// 4) Air quality -> value = pm25 (µg/m³)
// ------------------------------------------------------------
$result = mysqli_query($conn, 'SELECT location, latitude, longitude, pm25, severity, recorded_at FROM air_quality_data ORDER BY recorded_at DESC LIMIT 100');

if (!$result) {
    error_log('CityPulse normalized-data air_quality query failed: ' . mysqli_error($conn));
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Could not load normalized data. Please try again later.']);
    exit;
}

while ($row = mysqli_fetch_assoc($result)) {
    $records[] = [
        'source'     => 'air_quality',
        'event_type' => 'air_quality',
        'location'   => $row['location'],
        'latitude'   => (float) $row['latitude'],
        'longitude'  => (float) $row['longitude'],
        'value'      => (float) $row['pm25'],
        'severity'   => normalize_severity($row['severity']),
        'timestamp'  => $row['recorded_at'],
    ];
    $airQualityCount++;
    if ($latestTimestamp === null || $row['recorded_at'] > $latestTimestamp) {
        $latestTimestamp = $row['recorded_at'];
    }
}

// ------------------------------------------------------------
// 5) Send the normalized array + a small summary.
// ------------------------------------------------------------
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'count'   => count($records),
    'data'    => $records,
    'summary' => [
        'total_records'          => count($records),
        'weather_record_count'   => $weatherCount,
        'traffic_record_count'   => $trafficCount,
        'incident_record_count'  => $incidentCount,
        'air_quality_record_count' => $airQualityCount,
        'latest_timestamp'       => $latestTimestamp, // null when there is no data at all
    ],
], JSON_PRETTY_PRINT);