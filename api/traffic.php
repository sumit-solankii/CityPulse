<?php
/**
 * CityPulse - Traffic Data API
 *
 * Returns all traffic readings from the traffic_data table as JSON.
 * Newest records first.
 *
 * URL: /CityPulse/api/traffic.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/location.php';

$nearby = citypulse_request_location();
$distanceWhere = citypulse_distance_sql($nearby['latitude'], $nearby['longitude'], $nearby['radius_km'], 't');

// Fetch traffic readings from MySQL.
$result = mysqli_query($conn, 'SELECT t.id, t.location, t.latitude, t.longitude, t.delay_minutes, t.traffic_level, t.severity, t.recorded_at FROM traffic_data AS t WHERE ' . $distanceWhere . ' ORDER BY t.recorded_at DESC');

// Handle query errors without exposing credentials to the user.
if (!$result) {
    error_log('CityPulse traffic query failed: ' . mysqli_error($conn));
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Could not load traffic data. Please try again later.']);
    exit;
}

// Collect rows into a plain PHP array.
$traffic = [];
while ($row = mysqli_fetch_assoc($result)) {
    $traffic[] = $row;
}

// Send the data as JSON.
header('X-CityPulse-Fetched-At: ' . gmdate('c'));
header('Content-Type: application/json');
echo json_encode($traffic, JSON_PRETTY_PRINT);