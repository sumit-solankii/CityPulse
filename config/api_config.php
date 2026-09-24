<?php
/**
 * CityPulse - External API Configuration (Step 9)
 *
 * Holds configuration (like API keys) for EXTERNAL services only.
 * These values are used server-side (PHP). NEVER echo them into
 * JavaScript or HTML - that would expose a secret to visitors.
 *
 * ------------------------------------------------------------
 * AIR QUALITY - OpenAQ API v3 (https://openaq.org)
 * ------------------------------------------------------------
 * OpenAQ provides free, open air-quality data and requires a
 * free API key that is sent with the "X-API-Key" header.
 *
 * How to get a key:
 *   1. Go to   https://explore.openaq.org/register
 *   2. Create a free account and copy your API key.
 *   3. Paste it below inside the quotes, for example:
 *          $openaqKey = '9f8e7d6c5b4a...';
 *
 * Alternatively you can set an OPENAQ_API_KEY environment
 * variable on the server - it is used when present.
 */

$openaqKey = getenv('OPENAQ_API_KEY');
if (!is_string($openaqKey) || $openaqKey === '') {
    // Paste your OpenAQ API key here (inside the quotes).
    $openaqKey = 'YOUR_OPENAQ_API_KEY_HERE';
}

define('OPENAQ_API_KEY', $openaqKey);