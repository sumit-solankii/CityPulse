<?php
/**
 * CityPulse - Data Status API
 *
 * Returns a small JSON summary of the simulated civic data:
 *   - total traffic records
 *   - total incident records
 *   - latest traffic record time
 *   - latest incident record time
 *
 * URL: /CityPulse/api/data_status.php
 */

require_once __DIR__ . '/../config/database.php';

// Ask MySQL for the totals and latest timestamps of both tables.
$trafficResult = mysqli_query($conn, 'SELECT COUNT(*) AS total, MAX(recorded_at) AS latest FROM traffic_data');
$incidentsResult = mysqli_query($conn, 'SELECT COUNT(*) AS total, MAX(recorded_at) AS latest FROM incidents');

// Handle query errors without exposing credentials to the user.
if (!$trafficResult || !$incidentsResult) {
    error_log('CityPulse data_status query failed: ' . mysqli_error($conn));
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Could not load data status. Please try again later.']);
    exit;
}

$traffic = mysqli_fetch_assoc($trafficResult);
$incidents = mysqli_fetch_assoc($incidentsResult);

// Build the small summary payload.
$status = [
    'total_traffic_records'     => (int) $traffic['total'],
    'total_incident_records'    => (int) $incidents['total'],
    'latest_traffic_record_time' => $traffic['latest'],   // null when the table is empty
    'latest_incident_record_time' => $incidents['latest'], // null when the table is empty
];

// Send the summary as JSON.
header('Content-Type: application/json');
echo json_encode($status, JSON_PRETTY_PRINT);