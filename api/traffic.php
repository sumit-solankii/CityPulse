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

// Fetch traffic readings from MySQL.
$result = mysqli_query($conn, 'SELECT id, location, latitude, longitude, delay_minutes, traffic_level, severity, recorded_at FROM traffic_data ORDER BY recorded_at DESC');

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