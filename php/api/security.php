<?php
/** Request and value checks shared by the existing API endpoints. */

function normalize_http_origin(string $url, bool $allowPath = false): ?string
{
    if ($url === '' || preg_match('/\s/', $url)) {
        return null;
    }
    $parts = parse_url($url);
    if ($parts === false || empty($parts['scheme']) || empty($parts['host'])
        || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])) {
        return null;
    }
    $scheme = strtolower($parts['scheme']);
    $host = strtolower($parts['host']);
    if (!in_array($scheme, ['http', 'https'], true)
        || preg_match('/[^a-z0-9.\-:\[\]]/i', $host)
        || (!$allowPath && ($parts['path'] ?? '') !== '')) {
        return null;
    }
    $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
    $suffix = (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))
        ? '' : ':' . $port;
    return $scheme . '://' . $host . $suffix;
}

function apply_request_origin_policy(): void
{
    // A cached response must not reuse another browser origin's CORS headers.
    header('Vary: Origin', false);
    if (!isset($_SERVER['HTTP_ORIGIN'])) {
        return; // Non-browser clients still require the endpoint's own authorization.
    }

    $origin = normalize_http_origin($_SERVER['HTTP_ORIGIN']);
    $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
    $allowed = [
        normalize_http_origin($scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '')),
        normalize_http_origin(env('APP_BASE_URL', 'http://127.0.0.1:8000'), true),
    ];
    foreach (explode(',', env('CORS_ALLOWED_ORIGINS', '')) as $configured) {
        $allowed[] = normalize_http_origin(trim($configured), true);
    }
    if ($origin === null || !in_array($origin, array_filter($allowed), true)) {
        json_error('request origin is not allowed', 403);
    }

    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, X-Idempotency-Key');
}

function parse_nonnegative_quantity($value, string $fieldLabel, bool $wholeUnits = false): float
{
    if ((!is_int($value) && !is_float($value) && !is_string($value))
        || !is_numeric($value)) {
        json_error("$fieldLabel must be a non-negative number");
    }
    $quantity = (float) $value;
    if (!is_finite($quantity) || $quantity < 0 || $quantity > 99999999.99) {
        json_error("$fieldLabel must be between 0 and 99999999.99");
    }
    if ($quantity != round($quantity, 2)) {
        json_error("$fieldLabel must have at most 2 decimal places");
    }
    if ($wholeUnits && $quantity != floor($quantity)) {
        json_error("$fieldLabel must be a whole number for equipment");
    }
    return round($quantity, 2);
}

function parse_usage_month($value): string
{
    if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-01$/D', $value, $parts)
        || (int) $parts[1] < 1000 || !checkdate((int) $parts[2], 1, (int) $parts[1])) {
        json_error('month must be a valid YYYY-MM-01 date');
    }
    return $value;
}
