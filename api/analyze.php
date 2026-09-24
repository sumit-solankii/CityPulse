<?php
/**
 * CityPulse - Analysis API (Step 6)
 *
 * The core intelligence of CityPulse: a simple, explainable,
 * rule-based engine that:
 *
 *   1. Retrieves the normalized civic data (Step 5 endpoint).
 *   2. Detects anomalies using easy-to-change thresholds.
 *   3. Detects POSSIBLE correlations (coincidences in time + location)
 *      without claiming one event caused another.
 *   4. Calculates an overall Area Pulse.
 *
 * URL: /CityPulse/api/analyze.php
 *
 * IMPORTANT: correlations are labeled POSSIBLE. The engine only says
 * events "coincide" - it never claims one event caused another.
 */

// Keep PHP warnings/notices out of the JSON response.
ini_set('display_errors', '0');

// ------------------------------------------------------------
// Tunable thresholds - change these in ONE place.
// ------------------------------------------------------------
define('TRAFFIC_DELAY_NORMAL_MAX', 10);   // delay <= 10 min            -> NORMAL
define('TRAFFIC_DELAY_MODERATE_MAX', 20); // 10 < delay <= 20 min       -> MODERATE
                                          // delay > 20 min             -> HIGH

define('INCIDENTS_NORMAL_MAX', 10);   // fewer than 10 recent incidents  -> NORMAL
define('INCIDENTS_MODERATE_MAX', 20); // 10 to 20 recent incidents       -> MODERATE
                                      // more than 20                    -> HIGH

define('INCIDENT_RECENT_WINDOW_HOURS', 24); // how "recent" means for incident counts
define('CORRELATION_WINDOW_MINUTES', 15);   // max time gap for a possible correlation

// ------------------------------------------------------------
// Simple rule functions
// ------------------------------------------------------------
function traffic_severity($delayMinutes)
{
    if ($delayMinutes <= TRAFFIC_DELAY_NORMAL_MAX) return 'NORMAL';
    if ($delayMinutes <= TRAFFIC_DELAY_MODERATE_MAX) return 'MODERATE';
    return 'HIGH';
}

function incident_severity($count)
{
    if ($count < INCIDENTS_NORMAL_MAX) return 'NORMAL';
    if ($count <= INCIDENTS_MODERATE_MAX) return 'MODERATE';
    return 'HIGH';
}

// Absolute difference between two timestamps, in minutes.
function minutes_between($tsA, $tsB)
{
    return abs(strtotime($tsA) - strtotime($tsB)) / 60;
}

// ------------------------------------------------------------
// 1) Retrieve the normalized civic data from the Step 5 API.
// ------------------------------------------------------------
$scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host     = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
$scriptDir = isset($_SERVER['SCRIPT_NAME']) ? dirname($_SERVER['SCRIPT_NAME']) : '/CityPulse/api';

$context = stream_context_create([
    'http' => [
        'timeout' => 10,
        'ignore_errors' => true,
        'user_agent' => 'CityPulse analysis engine',
    ],
]);

$response = @file_get_contents("{$scheme}://{$host}{$scriptDir}/normalized-data.php", false, $context);
$normalized = json_decode($response, true);

// If the normalized feed is unavailable, return an empty, safe analysis.
if (!is_array($normalized) || empty($normalized['success']) || !isset($normalized['data'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'success'      => true,
        'analysis_time'=> date('Y-m-d H:i:s'),
        'area_pulse'   => 'NORMAL',
        'anomalies'    => [],
        'correlations' => [],
        'notice'       => 'No civic data available for analysis right now.',
    ], JSON_PRETTY_PRINT);
    exit;
}

$records = $normalized['data'];

// ------------------------------------------------------------
// 2) Organize the latest activity per location.
//    (The normalized payload is newest-first, so the first row
//     we see for a location + source is its latest event.)
// ------------------------------------------------------------
$weatherByLocation   = []; // location => ['severity' => ..., 'timestamp' => ...]
$trafficByLocation   = []; // location => ['delay' => ..., 'timestamp' => ...]
$incidentsByLocation = []; // location => ['count' => ..., 'timestamp' => ...]

$incidentCutoff = date('Y-m-d H:i:s', time() - INCIDENT_RECENT_WINDOW_HOURS * 3600);

foreach ($records as $record) {
    $loc = $record['location'];
    $ts  = $record['timestamp'];

    if ($record['source'] === 'weather') {
        if (!isset($weatherByLocation[$loc]) || $ts > $weatherByLocation[$loc]['timestamp']) {
            $weatherByLocation[$loc] = ['severity' => $record['severity'], 'timestamp' => $ts];
        }
    } elseif ($record['source'] === 'traffic') {
        if (!isset($trafficByLocation[$loc]) || $ts > $trafficByLocation[$loc]['timestamp']) {
            $trafficByLocation[$loc] = ['delay' => (int) $record['value'], 'timestamp' => $ts];
        }
    } elseif ($record['source'] === 'incident') {
        if (!isset($incidentsByLocation[$loc])) {
            $incidentsByLocation[$loc] = ['count' => 0, 'timestamp' => null];
        }
        // Count only "recent" incidents for the incident anomaly rule.
        if ($ts >= $incidentCutoff) {
            $incidentsByLocation[$loc]['count']++;
            if ($incidentsByLocation[$loc]['timestamp'] === null || $ts > $incidentsByLocation[$loc]['timestamp']) {
                $incidentsByLocation[$loc]['timestamp'] = $ts;
            }
        }
    }
}

// ------------------------------------------------------------
// 3) Anomaly detection (simple thresholds).
// ------------------------------------------------------------
$anomalies = [];

foreach ($trafficByLocation as $loc => $t) {
    $severity = traffic_severity($t['delay']);
    if ($severity !== 'NORMAL') {
        $anomalies[] = [
            'type'     => 'traffic',
            'location' => $loc,
            'severity' => $severity,
            'reason'   => "Traffic delay in {$loc} is {$t['delay']} minutes ({$severity} threshold).",
        ];
    }
}

foreach ($incidentsByLocation as $loc => $inc) {
    $severity = incident_severity($inc['count']);
    if ($severity !== 'NORMAL') {
        $anomalies[] = [
            'type'     => 'incident',
            'location' => $loc,
            'severity' => $severity,
            'reason'   => "{$inc['count']} recent incidents reported in {$loc} within the last " . INCIDENT_RECENT_WINDOW_HOURS . " hours ({$severity} threshold).",
        ];
    }
}

foreach ($weatherByLocation as $loc => $w) {
    if ($w['severity'] !== 'NORMAL') {
        $anomalies[] = [
            'type'     => 'weather',
            'location' => $loc,
            'severity' => $w['severity'],
            'reason'   => "Current weather severity in {$loc} is {$w['severity']}.",
        ];
    }
}

// ------------------------------------------------------------
// 4) Possible correlations (same area + ~15 minute window).
//    These are coincidence checks ONLY - never causation.
// ------------------------------------------------------------
$correlations = [];

$locations = array_values(array_unique(array_merge(
    array_keys($weatherByLocation),
    array_keys($trafficByLocation),
    array_keys($incidentsByLocation)
)));

foreach ($locations as $loc) {
    $w = isset($weatherByLocation[$loc])   ? $weatherByLocation[$loc]   : null;
    $t = isset($trafficByLocation[$loc])   ? $trafficByLocation[$loc]   : null;
    $i = isset($incidentsByLocation[$loc]) ? $incidentsByLocation[$loc] : null;

    $weatherSeverity  = $w ? $w['severity']              : 'NORMAL';
    $trafficSeverity  = $t ? traffic_severity($t['delay']) : 'NORMAL';
    $incidentSeverity = $i ? incident_severity($i['count']) : 'NORMAL';

    // Weather + traffic both HIGH in the same place, close in time.
    if ($w && $t && $weatherSeverity === 'HIGH' && $trafficSeverity === 'HIGH') {
        $diff = (int) round(minutes_between($w['timestamp'], $t['timestamp']));
        if ($diff <= CORRELATION_WINDOW_MINUTES) {
            $correlations[] = [
                'type'                   => 'possible_correlation',
                'location'               => $loc,
                'events'                 => ['weather', 'traffic'],
                'time_difference_minutes'=> $diff,
                'message'                => "Possible correlation detected between weather and traffic. Weather activity coincides with increased traffic activity in {$loc}.",
                'label'                  => 'POSSIBLE',
            ];
        }
    }

    // Weather + incidents both HIGH in the same place, close in time.
    if ($w && $i && $weatherSeverity === 'HIGH' && $incidentSeverity === 'HIGH') {
        $diff = (int) round(minutes_between($w['timestamp'], $i['timestamp']));
        if ($diff <= CORRELATION_WINDOW_MINUTES) {
            $correlations[] = [
                'type'                   => 'possible_correlation',
                'location'               => $loc,
                'events'                 => ['weather', 'incident'],
                'time_difference_minutes'=> $diff,
                'message'                => "Possible correlation detected between weather and incidents. Weather activity coincides with increased incident reports in {$loc}.",
                'label'                  => 'POSSIBLE',
            ];
        }
    }

    // Traffic + incidents both unusually high in the same place and window.
    if ($t && $i && $trafficSeverity === 'HIGH' && $incidentSeverity === 'HIGH') {
        $diff = (int) round(minutes_between($t['timestamp'], $i['timestamp']));
        if ($diff <= CORRELATION_WINDOW_MINUTES) {
            $correlations[] = [
                'type'                   => 'possible_correlation',
                'location'               => $loc,
                'events'                 => ['traffic', 'incident'],
                'time_difference_minutes'=> $diff,
                'message'                => "Traffic and incident activity increased around the same time in {$loc}.",
                'label'                  => 'POSSIBLE',
            ];
        }
    }
}

// ------------------------------------------------------------
// 5) Overall Area Pulse.
//    NORMAL   -> no significant anomalies
//    MODERATE -> one significant anomaly
//    HIGH     -> two or more significant anomalies OR a strong
//                multi-feed possible correlation
//    ("significant" = an anomaly with HIGH severity)
// ------------------------------------------------------------
$significantCount = 0;
foreach ($anomalies as $a) {
    if ($a['severity'] === 'HIGH') {
        $significantCount++;
    }
}

if ($significantCount >= 2 || count($correlations) > 0) {
    $pulse = 'HIGH';
} elseif ($significantCount === 1) {
    $pulse = 'MODERATE';
} else {
    $pulse = 'NORMAL';
}

// ------------------------------------------------------------
// 6) Return the analysis as JSON.
// ------------------------------------------------------------
header('Content-Type: application/json');
echo json_encode([
    'success'       => true,
    'analysis_time' => date('Y-m-d H:i:s'),
    'area_pulse'    => $pulse,
    'anomalies'     => $anomalies,
    'correlations'  => $correlations,
], JSON_PRETTY_PRINT);