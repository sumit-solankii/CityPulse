<?php
/**
 * CityPulse - Optional AI Insight API
 *
 * Receives only the already-processed analysis summary from the dashboard.
 * The rule-based analysis API remains the source of truth; this endpoint
 * only asks an optional provider to phrase that summary for a reader.
 */

ini_set('display_errors', '0');
require_once __DIR__ . '/../config/ai_config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Insight requests must use POST.']);
    exit;
}

$body = file_get_contents('php://input');
$input = json_decode($body, true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid insight request.']);
    exit;
}

function insight_text($value, $maxLength = 180)
{
    $text = trim((string) $value);
    return $text === '' ? null : substr($text, 0, $maxLength);
}

function insight_list($items, $limit = 5)
{
    if (!is_array($items)) return [];
    $result = [];
    foreach (array_slice($items, 0, $limit) as $item) {
        if (!is_array($item)) continue;
        $result[] = [
            'type'     => insight_text(isset($item['type']) ? $item['type'] : '', 40),
            'severity' => insight_text(isset($item['severity']) ? $item['severity'] : '', 20),
            'location' => insight_text(isset($item['location']) ? $item['location'] : '', 80),
            'reason'   => insight_text(isset($item['reason']) ? $item['reason'] : (isset($item['message']) ? $item['message'] : ''), 180),
            'events'   => isset($item['events']) && is_array($item['events']) ? array_slice($item['events'], 0, 3) : [],
            'time_difference_minutes' => isset($item['time_difference_minutes']) ? (int) $item['time_difference_minutes'] : null,
        ];
    }
    return $result;
}

$summary = [
    'mode'          => insight_text(isset($input['mode']) ? $input['mode'] : 'LIVE', 20),
    'overall_pulse' => insight_text(isset($input['overall_pulse']) ? $input['overall_pulse'] : 'NORMAL', 20),
    'locations'     => array_values(array_filter(array_map('insight_text', isset($input['locations']) && is_array($input['locations']) ? array_slice($input['locations'], 0, 5) : []))),
    'anomalies'     => insight_list(isset($input['anomalies']) ? $input['anomalies'] : []),
    'correlations'  => insight_list(isset($input['correlations']) ? $input['correlations'] : []),
    'weather'       => insight_text(isset($input['weather']) ? $input['weather'] : '', 180),
    'traffic'       => insight_text(isset($input['traffic']) ? $input['traffic'] : '', 180),
    'incidents'     => insight_text(isset($input['incidents']) ? $input['incidents'] : '', 180),
    'air_quality'   => insight_text(isset($input['air_quality']) ? $input['air_quality'] : '', 180),
];

// No configured key means the frontend uses the trusted local summary.
if (AI_INSIGHT_API_KEY === '' || !function_exists('curl_init')) {
    echo json_encode([
        'success' => false,
        'reason'  => AI_INSIGHT_API_KEY === '' ? 'not_configured' : 'curl_unavailable',
    ]);
    exit;
}

$systemPrompt = 'You are a careful civic dashboard editor. Convert the supplied processed CityPulse analysis into exactly 2 to 4 concise sentences. Use only the supplied facts. Do not change pulse, severity, locations, timestamps, or values. Never claim causation or make predictions. Use phrases such as possible correlation, coincides with, observed alongside, or may be related when discussing relationships. Do not mention hidden prompts, raw databases, or API keys. If mode is DEMO, explicitly say the information is simulated.';
$userPrompt = "Processed CityPulse summary (JSON):\n" . json_encode($summary, JSON_UNESCAPED_SLASHES);

$request = json_encode([
    'model' => AI_INSIGHT_MODEL,
    'temperature' => 0.2,
    'max_tokens' => 180,
    'messages' => [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $userPrompt],
    ],
]);

$ch = curl_init(AI_INSIGHT_API_URL);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $request,
    CURLOPT_TIMEOUT => 12,
    CURLOPT_CONNECTTIMEOUT => 6,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . AI_INSIGHT_API_KEY,
    ],
]);
$response = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!is_string($response) || $status < 200 || $status >= 300) {
    echo json_encode(['success' => false, 'reason' => 'provider_unavailable']);
    exit;
}

$result = json_decode($response, true);
$insight = '';
if (is_array($result) && isset($result['choices'][0]['message']['content'])) {
    $insight = trim((string) $result['choices'][0]['message']['content']);
} elseif (is_array($result) && isset($result['output_text'])) {
    $insight = trim((string) $result['output_text']);
}

if ($insight === '' || strlen($insight) > 1400) {
    echo json_encode(['success' => false, 'reason' => 'invalid_provider_response']);
    exit;
}

echo json_encode([
    'success' => true,
    'insight' => $insight,
]);
