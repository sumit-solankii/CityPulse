<?php
/**
 * Shared nearby-location request helpers.
 *
 * Coordinates are supplied by the browser for the current request only.
 * They are never stored by this helper.
 */

define('CITYPULSE_DEFAULT_RADIUS_KM', 10.0);

function citypulse_request_location($required = true)
{
    $latitude = isset($_GET['latitude']) ? filter_var($_GET['latitude'], FILTER_VALIDATE_FLOAT) : false;
    $longitude = isset($_GET['longitude']) ? filter_var($_GET['longitude'], FILTER_VALIDATE_FLOAT) : false;
    $radius = isset($_GET['radius_km']) ? filter_var($_GET['radius_km'], FILTER_VALIDATE_FLOAT) : CITYPULSE_DEFAULT_RADIUS_KM;

    if ($latitude === false || $longitude === false || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
        if (!$required) return null;
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'A valid current latitude and longitude are required.']);
        exit;
    }

    if ($radius === false || $radius <= 0) $radius = CITYPULSE_DEFAULT_RADIUS_KM;
    $radius = min(25.0, max(5.0, (float) $radius));

    return [
        'latitude' => (float) $latitude,
        'longitude' => (float) $longitude,
        'radius_km' => $radius,
    ];
}

function citypulse_distance_sql($latitude, $longitude, $radiusKm, $tableAlias = '')
{
    $prefix = $tableAlias === '' ? '' : $tableAlias . '.';
    return '(6371 * ACOS(LEAST(1, GREATEST(-1, COS(RADIANS(' . (float) $latitude . '))'
        . ' * COS(RADIANS(' . $prefix . 'latitude))'
        . ' * COS(RADIANS(' . $prefix . 'longitude) - RADIANS(' . (float) $longitude . '))'
        . ' + SIN(RADIANS(' . (float) $latitude . ')) * SIN(RADIANS(' . $prefix . 'latitude)))))) <= ' . (float) $radiusKm;
}

function citypulse_distance_km($latitudeA, $longitudeA, $latitudeB, $longitudeB)
{
    $earthKm = 6371.0;
    $dLat = deg2rad($latitudeB - $latitudeA);
    $dLng = deg2rad($longitudeB - $longitudeA);
    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($latitudeA)) * cos(deg2rad($latitudeB)) * sin($dLng / 2) ** 2;
    return $earthKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
}