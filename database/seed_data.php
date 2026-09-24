<?php
/**
 * CityPulse - Simulated Seed Data (Step 3)
 *
 * Inserts realistic demo traffic and incident data for three zones
 * around Jaipur, Rajasthan, spread across the recent past so we can
 * later demonstrate how activity changes over time.
 *
 *   Zone A  -> high activity: traffic delay and incident count
 *              gradually increase, some incidents are High severity.
 *   Zone B  -> mostly normal activity.
 *   Zone C  -> moderate activity.
 *
 * Safety / how to re-run:
 *   This script is safe to run more than once. It first deletes the
 *   existing rows from traffic_data and incidents (all current rows
 *   are simulated demo data), then inserts a fresh dataset.
 *
 * How to run:
 *   Option A (command line):
 *       php C:\xampps\htdocs\CityPulse\database\seed_data.php
 *   Option B (browser):
 *       http://localhost/CityPulse/database/seed_data.php
 */

require_once __DIR__ . '/../config/database.php';

// ------------------------------------------------------------
// Helpers
// ------------------------------------------------------------

// Convert "hours ago" / "minutes ago" into a MySQL datetime string.
function seed_hours_ago($hours) { return date('Y-m-d H:i:s', time() - $hours * 3600); }
function seed_minutes_ago($minutes) { return date('Y-m-d H:i:s', time() - $minutes * 60); }

// Insert one traffic row. Returns true on success.
function seed_insert_traffic($conn, $location, $lat, $lng, $delay, $level, $severity, $recordedAt) {
    $stmt = mysqli_prepare($conn, 'INSERT INTO traffic_data (location, latitude, longitude, delay_minutes, traffic_level, severity, recorded_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($stmt, 'sddisss', $location, $lat, $lng, $delay, $level, $severity, $recordedAt);
    mysqli_stmt_execute($stmt);
    $ok = mysqli_stmt_affected_rows($stmt) === 1;
    mysqli_stmt_close($stmt);
    return $ok;
}

// Insert one incident row. Returns true on success.
function seed_insert_incident($conn, $location, $lat, $lng, $type, $description, $severity, $recordedAt) {
    $stmt = mysqli_prepare($conn, 'INSERT INTO incidents (location, latitude, longitude, incident_type, description, severity, recorded_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($stmt, 'sddssss', $location, $lat, $lng, $type, $description, $severity, $recordedAt);
    mysqli_stmt_execute($stmt);
    $ok = mysqli_stmt_affected_rows($stmt) === 1;
    mysqli_stmt_close($stmt);
    return $ok;
}

// ------------------------------------------------------------
// 1) Clear the old simulated data (safe to re-run)
// ------------------------------------------------------------
mysqli_query($conn, 'DELETE FROM traffic_data');
mysqli_query($conn, 'DELETE FROM incidents');
echo "Cleared old simulated traffic and incident data.\n";

// ------------------------------------------------------------
// 2) Zone data (around Jaipur, Rajasthan)
// ------------------------------------------------------------
$zoneA = ['location' => 'Zone A', 'lat' => 26.912400, 'lng' => 75.787300]; // city center - high activity
$zoneB = ['location' => 'Zone B', 'lat' => 26.898000, 'lng' => 75.778000]; // residential - normal activity
$zoneC = ['location' => 'Zone C', 'lat' => 26.921000, 'lng' => 75.805000]; // mixed area - moderate activity

// ------------------------------------------------------------
// 3) Zone A - traffic: delay gradually rises from 5 to 65 minutes
//    over the last 24 hours (one reading every 2 hours).
// ------------------------------------------------------------
$delay = 5; // minutes
for ($i = 0; $i < 12; $i++) {
    $level    = $delay < 15 ? 'Light' : ($delay < 40 ? 'Moderate' : 'Heavy');
    $severity = $delay < 15 ? 'Low'   : ($delay < 40 ? 'Moderate' : 'High');
    seed_insert_traffic($conn, $zoneA['location'], $zoneA['lat'], $zoneA['lng'], $delay, $level, $severity, seed_hours_ago(24 - $i * 2));
    $delay += 5;
}

// ------------------------------------------------------------
// 4) Zone A - incidents: start with minor reports, then escalate
//    to major, High-severity incidents closer to the present.
// ------------------------------------------------------------
$zoneAIncidents = [
    ['Road Closure',            'Ajmeri Gate flyover partially closed for repairs.',           'High',     120],
    ['Major Water Pipeline Burst', 'Main water line burst near the market, flooding the road.', 'High',     300],
    ['Power Outage',            '2 city blocks without power due to a transformer fault.',     'High',     540],
    ['Drain Overflow',          'Open drain overflowing onto the sidewalk.',                   'Moderate', 720],
    ['Traffic Signal Fault',    'Signal stuck on red at the main junction.',                   'Moderate', 900],
    ['Pothole Reported',        'Large pothole on the bus route damaging vehicles.',           'Moderate', 1020],
    ['Water Seepage Report',    'Water seeping from an old pipe, low pressure nearby.',        'Low',      1140],
    ['Street Light Outage',     'Street lights out on one stretch of the market road.',        'Low',      1320],
    ['Garbage Dumping Complaint', 'Unofficial garbage dumping on an empty plot.',              'Low',      1500],
];
foreach ($zoneAIncidents as $incident) {
    seed_insert_incident($conn, $zoneA['location'], $zoneA['lat'], $zoneA['lng'], $incident[0], $incident[1], $incident[2], seed_minutes_ago($incident[3]));
}

// ------------------------------------------------------------
// 5) Zone B - traffic: mostly normal (Light/Moderate, low delays).
// ------------------------------------------------------------
$zoneBTraffic = [
    [5,  'Light',     'Low'],
    [7,  'Light',     'Low'],
    [6,  'Light',     'Low'],
    [8,  'Moderate',  'Low'],
    [9,  'Moderate',  'Low'],
    [7,  'Light',     'Low'],
    [11, 'Moderate',  'Moderate'],
    [12, 'Moderate',  'Moderate'],
];
for ($i = 0; $i < count($zoneBTraffic); $i++) {
    seed_insert_traffic($conn, $zoneB['location'], $zoneB['lat'], $zoneB['lng'], $zoneBTraffic[$i][0], $zoneBTraffic[$i][1], $zoneBTraffic[$i][2], seed_hours_ago(24 - $i * 3));
}

// ------------------------------------------------------------
// 6) Zone B - incidents: a few minor, everyday reports.
// ------------------------------------------------------------
$zoneBIncidents = [
    ['Garbage Dumping Complaint', 'Weekly garbage pickup missed on the street.',               'Low',      480],
    ['Street Light Out',          'One street light not working near the park.',               'Low',      960],
    ['Minor Pothole',             'Small pothole forming near the entrance gate.',             'Low',      1500],
];
foreach ($zoneBIncidents as $incident) {
    seed_insert_incident($conn, $zoneB['location'], $zoneB['lat'], $zoneB['lng'], $incident[0], $incident[1], $incident[2], seed_minutes_ago($incident[3]));
}

// ------------------------------------------------------------
// 7) Zone C - traffic: moderate activity (moderate delays).
// ------------------------------------------------------------
$zoneCTraffic = [
    [16, 'Moderate', 'Moderate'],
    [19, 'Moderate', 'Moderate'],
    [18, 'Moderate', 'Moderate'],
    [22, 'Moderate', 'Moderate'],
    [20, 'Moderate', 'Moderate'],
    [25, 'Heavy',    'Moderate'],
    [24, 'Moderate', 'Moderate'],
    [28, 'Heavy',    'High'],
    [26, 'Heavy',    'High'],
    [30, 'Heavy',    'High'],
];
for ($i = 0; $i < count($zoneCTraffic); $i++) {
    seed_insert_traffic($conn, $zoneC['location'], $zoneC['lat'], $zoneC['lng'], $zoneCTraffic[$i][0], $zoneCTraffic[$i][1], $zoneCTraffic[$i][2], seed_hours_ago(24 - $i * 2));
}

// ------------------------------------------------------------
// 8) Zone C - incidents: a mix of everyday and moderate issues.
// ------------------------------------------------------------
$zoneCIncidents = [
    ['Garbage Dumping Complaint', 'Trash pile growing near the bus stop.',                    'Low',      180],
    ['Drain Overflow',            'Drain blocked after rain near the shop lane.',              'Moderate', 360],
    ['Traffic Signal Fault',      'Signal timing off during rush hour.',                       'Moderate', 600],
    ['Water Seepage Report',      'Seepage on a residential wall, minor leak suspected.',      'Moderate', 840],
    ['Pothole Reported',          'Potholes on the service road causing slow traffic.',        'Low',      1320],
];
foreach ($zoneCIncidents as $incident) {
    seed_insert_incident($conn, $zoneC['location'], $zoneC['lat'], $zoneC['lng'], $incident[0], $incident[1], $incident[2], seed_minutes_ago($incident[3]));
}

// ------------------------------------------------------------
// 9) Show a summary of what was inserted.
// ------------------------------------------------------------
$trafficTotal = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(*) AS total FROM traffic_data'))['total'];
$incidentTotal = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(*) AS total FROM incidents'))['total'];

echo "Seeding complete!\n";
echo "  Traffic records inserted: $trafficTotal\n";
echo "  Incident records inserted: $incidentTotal\n";
echo "  Zones: Zone A (high activity), Zone B (normal), Zone C (moderate)\n";
echo "You can now view the data at http://localhost/CityPulse/data-test.php\n";

mysqli_close($conn);