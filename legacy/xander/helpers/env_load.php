<?php
/**
 * Load project root .env into getenv() / $_ENV.
 * Does not override meaningful values already set by the server (trimmed non-empty).
 *
 * Important:
 * - On Windows/XAMPP, getenv may return "" or whitespace for "unset" keys.
 * - If getenv returns only spaces, we must NOT skip .env — that was leaving WHATSAPP_* empty.
 */
function xander_load_env_file()
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
    if (!is_readable($path)) {
        return;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return;
    }
    // Strip UTF-8 BOM so the first variable name parses
    if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
        $raw = substr($raw, 3);
    }
    $lines = preg_split("/\r\n|\n|\r/", $raw);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        $parts = explode('=', $line, 2);
        $k = trim($parts[0]);
        $v = isset($parts[1]) ? trim($parts[1]) : '';
        if ($v !== '' && (substr($v, 0, 1) === '"' || substr($v, 0, 1) === "'")) {
            $v = trim($v, "\"'");
        }
        if ($k === '') {
            continue;
        }
        $fromGetenv = getenv($k);
        $gTrim = ($fromGetenv !== false) ? trim((string) $fromGetenv) : '';
        $fromSuper = isset($_ENV[$k]) ? trim((string) $_ENV[$k]) : '';
        $fromServer = isset($_SERVER[$k]) ? trim((string) $_SERVER[$k]) : '';
        $hasNonEmpty = ($gTrim !== '') || ($fromSuper !== '') || ($fromServer !== '');
        if ($hasNonEmpty) {
            continue;
        }
        if (function_exists('putenv')) {
            @putenv($k . '=' . $v);
        }
        $_ENV[$k] = $v;
        $_SERVER[$k] = $v;
    }
}

/**
 * Read one key directly from .env (no cache). Used when normal resolution still returns empty.
 */
function xander_env_get_from_dotenv_file(string $key): string
{
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
    if (!is_readable($path) || $key === '') {
        return '';
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return '';
    }
    if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
        $raw = substr($raw, 3);
    }
    $lines = preg_split("/\r\n|\n|\r/", $raw);
    if ($lines === false) {
        return '';
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        $parts = explode('=', $line, 2);
        $k = trim($parts[0]);
        if (strcasecmp($k, $key) !== 0) {
            continue;
        }
        $v = isset($parts[1]) ? trim($parts[1]) : '';
        if ($v !== '' && (substr($v, 0, 1) === '"' || substr($v, 0, 1) === "'")) {
            $v = trim($v, "\"'");
        }

        return $v;
    }

    return '';
}

/**
 * Read a non-empty env value after .env is loaded. Prefers $_ENV, then getenv, then $_SERVER.
 * Falls back to a direct .env parse for known critical keys if still empty.
 */
function xander_env_get(string $key): string
{
    xander_load_env_file();
    if (isset($_ENV[$key])) {
        $v = trim((string) $_ENV[$key]);
        if ($v !== '') {
            return $v;
        }
    }
    $g = getenv($key);
    if ($g !== false && trim((string) $g) !== '') {
        return trim((string) $g);
    }
    if (isset($_SERVER[$key]) && trim((string) $_SERVER[$key]) !== '') {
        return trim((string) $_SERVER[$key]);
    }

    static $directKeys = null;
    if ($directKeys === null) {
        $directKeys = [
            'WHATSAPP_ACCESS_TOKEN' => true,
            'WHATSAPP_PHONE_NUMBER_ID' => true,
            'WHATSAPP_DEFAULT_COUNTRY_CODE' => true,
            'META_GRAPH_VERSION' => true,
        ];
    }
    if (isset($directKeys[$key])) {
        $v = xander_env_get_from_dotenv_file($key);
        if ($v !== '') {
            $_ENV[$key] = $v;
            $_SERVER[$key] = $v;
            if (function_exists('putenv')) {
                @putenv($key . '=' . $v);
            }

            return $v;
        }
    }

    return '';
}

/**
 * Append a line to whatsapp_debug.log (project root) or PHP temp dir if not writable.
 * Never logs secret values — only lengths and booleans.
 */
function xander_whatsapp_debug_file_append(string $line): void
{
    $root = dirname(__DIR__);
    $primary = $root . DIRECTORY_SEPARATOR . 'whatsapp_debug.log';
    $payload = date('c') . ' ' . $line . PHP_EOL;
    $written = @file_put_contents($primary, $payload, FILE_APPEND | LOCK_EX);
    if ($written !== false) {
        return;
    }
    $fallback = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'xander_whatsapp_debug.log';
    $note = date('c') . ' [fallback] primary_unwritable=' . $primary . ' using=' . $fallback . PHP_EOL;
    @file_put_contents($fallback, $note . $payload, FILE_APPEND | LOCK_EX);
}

/**
 * Full diagnostic when WhatsApp token/phone ID resolve empty (for debugging .env loading).
 */
function xander_whatsapp_env_debug_report_missing_credentials(): void
{
    $root = dirname(__DIR__);
    $envPath = $root . DIRECTORY_SEPARATOR . '.env';
    $helpersDir = __DIR__;

    $scanEnvFileForKey = static function (string $path, string $key): array {
        if (!is_readable($path)) {
            return ['readable' => false, 'line_found' => false, 'line_starts_with_key' => false];
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return ['readable' => true, 'read_ok' => false, 'line_found' => false];
        }
        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
            $raw = substr($raw, 3);
        }
        $lines = preg_split("/\r\n|\n|\r/", $raw);
        if ($lines === false) {
            return ['readable' => true, 'read_ok' => true, 'line_found' => false];
        }
        $prefix = $key . '=';
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '' || strpos($t, '#') === 0) {
                continue;
            }
            if (stripos($t, $prefix) === 0 || preg_match('/^' . preg_quote($key, '/') . '\s*=/i', $t)) {
                return [
                    'readable' => true,
                    'read_ok' => true,
                    'line_found' => true,
                    'line_has_equals' => strpos($t, '=') !== false,
                    'value_char_len' => (strpos($t, '=') !== false) ? strlen(trim(explode('=', $t, 2)[1] ?? '')) : 0,
                ];
            }
        }

        return ['readable' => true, 'read_ok' => true, 'line_found' => false];
    };

    $keyInfo = static function (string $key) use ($scanEnvFileForKey, $envPath): array {
        $g = getenv($key);
        $fromFile = xander_env_get_from_dotenv_file($key);

        return [
            'key' => $key,
            'len__ENV' => isset($_ENV[$key]) ? strlen(trim((string) $_ENV[$key])) : null,
            'len_getenv' => ($g === false) ? null : strlen(trim((string) $g)),
            'getenv_is_false' => $g === false,
            'len_SERVER' => isset($_SERVER[$key]) ? strlen(trim((string) $_SERVER[$key])) : null,
            'len_direct_file_parse' => strlen($fromFile),
            'env_file_scan' => $scanEnvFileForKey($envPath, $key),
        ];
    };

    $data = [
        'php_sapi' => PHP_SAPI,
        'php_version' => PHP_VERSION,
        'helpers_dir' => $helpersDir,
        'project_root' => $root,
        'env_path' => $envPath,
        'env_realpath' => @realpath($envPath) ?: null,
        'env_file_exists' => file_exists($envPath),
        'env_is_readable' => is_readable($envPath),
        'env_bytes' => file_exists($envPath) ? @filesize($envPath) : null,
        'ini_open_basedir' => ini_get('open_basedir') ?: '',
        'script' => $_SERVER['SCRIPT_FILENAME'] ?? '',
        'cwd' => function_exists('getcwd') ? @getcwd() : '',
        'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? '',
        'putenv_exists' => function_exists('putenv'),
        'WHATSAPP_ACCESS_TOKEN' => $keyInfo('WHATSAPP_ACCESS_TOKEN'),
        'WHATSAPP_PHONE_NUMBER_ID' => $keyInfo('WHATSAPP_PHONE_NUMBER_ID'),
    ];

    xander_whatsapp_debug_file_append('WHATSAPP_ENV_DEBUG ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
