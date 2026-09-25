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

// Fetch incidents from MySQL.
$result = mysqli_query($conn, 'SELECT id, location, latitude, longitude, incident_type, description, severity, recorded_at FROM incidents ORDER BY recorded_at DESC');

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