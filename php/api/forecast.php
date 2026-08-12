<?php
/**
 * GET /api/forecast.php?item=<item_key>
 *
 * Proxies to the Python Prophet microservice (forecast_service.py) running
 * on 127.0.0.1:5050. PHP never runs Prophet itself — it just forwards the
 * request and passes the JSON straight through.
 */
require __DIR__ . '/config.php';

require_auth();

$itemKey = $_GET['item'] ?? '';
if ($itemKey === '') {
    json_error('missing required query param: item');
}

$url = FORECAST_SERVICE_URL . '/api/forecast/' . rawurlencode($itemKey);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Prophet fitting can take a few seconds
$response = curl_exec($ch);
$curlErrno = curl_errno($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curlErrno !== 0 || $response === false) {
    json_error('Forecast service unavailable — is forecast_service.py running on port 5050?', 503);
}

http_response_code($httpCode ?: 502);
echo $response;
