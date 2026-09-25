<?php
/**
 * CityPulse - Incidents API
 *
 * Returns all civic incident reports from the incidents table as JSON.
 * Newest records first.
 *
 * URL: /CityPulse/api/incidents.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/location.php';

$nearby = citypulse_request_location();
$distanceWhere = citypulse_distance_sql($nearby['latitude'], $nearby['longitude'], $nearby['radius_km'], 'i');

// Fetch incidents from MySQL.
$result = mysqli_query($conn, 'SELECT i.id, i.location, i.latitude, i.longitude, i.incident_type, i.description, i.severity, i.recorded_at FROM incidents AS i WHERE ' . $distanceWhere . ' ORDER BY i.recorded_at DESC');

// Handle query errors without exposing credentials to the user.
if (!$result) {
    error_log('CityPulse incidents query failed: ' . mysqli_error($conn));
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Could not load incident data. Please try again later.']);
    exit;
}

// Collect rows into a plain PHP array.
$incidents = [];
while ($row = mysqli_fetch_assoc($result)) {
    $incidents[] = $row;
}

// Send the data as JSON.
header('X-CityPulse-Fetched-At: ' . gmdate('c'));
header('Content-Type: application/json');
echo json_encode($incidents, JSON_PRETTY_PRINT);