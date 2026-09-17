<?php
/**
 * Minimal .env loader — no Composer dependency, consistent with this
 * project's zero-dependency PHP style (same idea as forecast_service.py /
 * ocr_service.py already reading DB_HOST/DB_USER/DB_PASSWORD/DB_NAME from
 * os.environ). Real environment variables (set by the OS, a process
 * manager, or a container) always win over the .env file.
 *
 * Copy .env.example to .env at the project root and fill in real values —
 * .env itself is gitignored, .env.example is committed as documentation.
 */

function load_env_file(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if ($key === '') {
            continue;
        }
        // Strip matching surrounding quotes, e.g. DB_PASSWORD="secret".
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }
        // Don't clobber a real environment variable that's already set.
        if (getenv($key) === false && !array_key_exists($key, $_ENV)) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

/** Reads an env var (real environment first, then .env file), falling back to $default. */
function env(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? getenv($key);
    return ($value !== false && $value !== null && $value !== '') ? $value : $default;
}

load_env_file(__DIR__ . '/../../.env');
