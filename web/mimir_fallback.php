<?php

/**
 * Mímir-fallback voor Janus: circuit, BC-environment/auth en de directe OData-route.
 * web/odata.php laadt dit bestand en roept het aan vanuit de bestaande entry points.
 */

/**
 * @return array{open: bool, error: ?Throwable}
 */
function &odata_mimir_circuit_state(): array
{
    static $state = [
        'open' => false,
        'error' => null,
    ];
    return $state;
}

function odata_mimir_circuit_open(): bool
{
    $state = &odata_mimir_circuit_state();
    return $state['open'] === true;
}

function odata_mimir_last_error(): ?Throwable
{
    $state = &odata_mimir_circuit_state();
    return $state['error'] instanceof Throwable ? $state['error'] : null;
}

function odata_mimir_trip(Throwable $exception): void
{
    $state = &odata_mimir_circuit_state();
    if ($state['open'] === true) {
        return;
    }
    $state['open'] = true;
    $state['error'] = $exception;
}

function odata_mimir_circuit_reset(): void
{
    $state = &odata_mimir_circuit_state();
    $state['open'] = false;
    $state['error'] = null;
}

function odata_mimir_connect_timeout_seconds(): int
{
    return 10;
}

function odata_mimir_timeout_seconds_for_sapi(string $sapi): int
{
    return strtolower($sapi) === 'cli' ? 600 : 90;
}

function odata_mimir_timeout_seconds(): int
{
    return odata_mimir_timeout_seconds_for_sapi(PHP_SAPI);
}

function odata_mimir_fail(Exception $exception): void
{
    odata_mimir_trip($exception);
    throw $exception;
}

function odata_auth_is_usable($auth): bool
{
    if (!is_array($auth)) {
        return false;
    }
    $user = trim((string) ($auth['user'] ?? ''));
    if ($user === '') {
        return false;
    }
    $mode = (string) ($auth['mode'] ?? '');
    if ($mode !== 'basic' && $mode !== 'ntlm') {
        return false;
    }
    return array_key_exists('pass', $auth);
}

/**
 * Leest auth.php in een closure. Assignments daarin zijn anders lokaal aan de
 * aanroepende functie. Het pad is vast: web/auth.php.
 *
 * @return array<string, mixed>
 */
function odata_read_auth_php_vars(): array
{
    if (!is_file(__DIR__ . '/auth.php')) {
        return [];
    }
    return (static function (): array {
        require __DIR__ . '/auth.php';
        return get_defined_vars();
    })();
}

/**
 * Ontbrekende BC-globals worden naar $GLOBALS gekopieerd.
 * Waarden die al gezet zijn blijven staan.
 */
function odata_ensure_bc_config_loaded(): void
{
    if (!empty($GLOBALS['JANUS_BC_AUTH_LOAD_TRIED'])) {
        return;
    }
    $names = ['baseUrl', 'auth', 'auth_list', 'environment', 'base'];
    $missing = false;
    foreach (['baseUrl', 'auth', 'auth_list', 'environment'] as $name) {
        if (!array_key_exists($name, $GLOBALS)) {
            $missing = true;
            break;
        }
    }
    if (!$missing) {
        $GLOBALS['JANUS_BC_AUTH_LOAD_TRIED'] = true;
        return;
    }
    $GLOBALS['JANUS_BC_AUTH_LOAD_TRIED'] = true;
    $loaded = odata_read_auth_php_vars();
    foreach ($names as $name) {
        if (!array_key_exists($name, $loaded) || array_key_exists($name, $GLOBALS)) {
            continue;
        }
        $GLOBALS[$name] = $loaded[$name];
    }
}

function odata_bc_base_url(): ?string
{
    odata_ensure_bc_config_loaded();
    global $baseUrl;
    if (!isset($baseUrl) || !is_string($baseUrl)) {
        return null;
    }
    $base = trim($baseUrl);
    if ($base === '' || stripos($base, 'mimir.invalid') !== false) {
        return null;
    }
    return $base;
}

function odata_bc_environment(): ?string
{
    odata_ensure_bc_config_loaded();
    global $environment, $auth_list;

    $candidates = [];
    if (isset($environment) && is_string($environment)) {
        $candidates[] = $environment;
    } elseif (isset($environment) && is_array($environment)) {
        foreach ($environment as $item) {
            if (is_string($item) || is_int($item)) {
                $candidates[] = (string) $item;
            }
        }
    }
    foreach ($candidates as $candidate) {
        $env = trim($candidate);
        if ($env === '' || strcasecmp($env, 'mimir') === 0) {
            continue;
        }
        return $env;
    }
    if (isset($auth_list) && is_array($auth_list)) {
        foreach ($auth_list as $key => $entry) {
            if (!odata_auth_is_usable($entry)) {
                continue;
            }
            $env = trim((string) $key);
            if ($env === '' || strcasecmp($env, 'mimir') === 0) {
                continue;
            }
            return $env;
        }
    }
    return null;
}

function odata_bc_env_from_map(array $map, string $company): ?string
{
    $pairs = [];
    if (isset($map[$company])) {
        $pairs[] = $map[$company];
    }
    foreach ($map as $name => $env) {
        if (strcasecmp((string) $name, $company) === 0) {
            $pairs[] = $env;
        }
    }
    foreach ($pairs as $env) {
        $envName = trim((string) $env);
        if ($envName !== '' && strcasecmp($envName, 'mimir') !== 0) {
            return $envName;
        }
    }
    return null;
}

function odata_bc_mapped_environment(string $company): ?string
{
    $company = trim($company);
    if ($company === '') {
        return null;
    }
    $cached = $GLOBALS['demeter_company_environment_map'] ?? null;
    if (is_array($cached)) {
        $found = odata_bc_env_from_map($cached, $company);
        if ($found !== null) {
            return $found;
        }
    }
    if (!empty($GLOBALS['JANUS_BC_ENV_LOOKUP']) || !function_exists('odata_mimir_company_environment_map')) {
        return null;
    }
    $GLOBALS['JANUS_BC_ENV_LOOKUP'] = true;
    try {
        $fresh = odata_mimir_company_environment_map(null);
    } catch (Throwable $ignored) {
        $fresh = [];
    }
    $GLOBALS['JANUS_BC_ENV_LOOKUP'] = false;
    if (!is_array($fresh)) {
        return null;
    }
    return odata_bc_env_from_map($fresh, $company);
}

function odata_bc_environment_for_company(string $company): ?string
{
    $mapped = odata_bc_mapped_environment($company);
    if ($mapped !== null) {
        return $mapped;
    }
    return odata_bc_environment();
}

function odata_bc_environment_from_odata_url(string $url): ?string
{
    $parts = parse_url($url);
    $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
    if (preg_match('#^/([^/]+)/#', $path, $match) === 1) {
        $segment = trim(rawurldecode($match[1]));
        if ($segment !== '' && strcasecmp($segment, 'mimir') !== 0) {
            return $segment;
        }
    }
    if (function_exists('odata_mimir_parse_entity_url')) {
        $parsed = odata_mimir_parse_entity_url($url);
        if (is_array($parsed) && isset($parsed['company'])) {
            return odata_bc_environment_for_company((string) $parsed['company']);
        }
    }
    return odata_bc_environment();
}

/**
 * @return list<string>
 */
function odata_bc_environment_list(?string $environmentFilter = null): array
{
    $filter = $environmentFilter !== null ? trim($environmentFilter) : '';
    if ($filter !== '' && strcasecmp($filter, 'mimir') !== 0) {
        return [$filter];
    }

    $envs = [];
    global $auth_list;
    if (isset($auth_list) && is_array($auth_list)) {
        foreach (array_keys($auth_list) as $key) {
            $env = trim((string) $key);
            if ($env === '' || strcasecmp($env, 'mimir') === 0) {
                continue;
            }
            $envs[] = $env;
        }
    }
    if ($envs === []) {
        $env = odata_bc_environment();
        if ($env !== null) {
            $envs[] = $env;
        }
    }
    return $envs;
}

function odata_bc_auth_for_environment(?string $env): ?array
{
    if ($env === null) {
        return null;
    }
    $env = trim($env);
    if ($env === '' || strcasecmp($env, 'mimir') === 0) {
        return null;
    }
    global $auth_list;
    if (!isset($auth_list) || !is_array($auth_list)) {
        return null;
    }
    if (isset($auth_list[$env]) && odata_auth_is_usable($auth_list[$env])) {
        return $auth_list[$env];
    }
    foreach ($auth_list as $key => $entry) {
        if (strcasecmp((string) $key, $env) === 0 && odata_auth_is_usable($entry)) {
            return $entry;
        }
    }
    return null;
}

function odata_bc_auth_for_fallback(array $passed): ?array
{
    odata_ensure_bc_config_loaded();
    if (odata_auth_is_usable($passed)) {
        return $passed;
    }
    global $auth;
    if (isset($auth) && odata_auth_is_usable($auth)) {
        return $auth;
    }
    $fromEnv = odata_bc_auth_for_environment(odata_bc_environment());
    if ($fromEnv !== null) {
        return $fromEnv;
    }
    global $auth_list;
    if (isset($auth_list) && is_array($auth_list)) {
        foreach ($auth_list as $entry) {
            if (odata_auth_is_usable($entry)) {
                return $entry;
            }
        }
    }
    return null;
}

function odata_bc_auth_for_company_env(?string $env, array $passed): ?array
{
    $fromEnv = odata_bc_auth_for_environment($env);
    if ($fromEnv !== null) {
        return $fromEnv;
    }
    return odata_bc_auth_for_fallback($passed);
}

function odata_bc_credentials_configured(): bool
{
    if (odata_bc_base_url() === null || odata_bc_environment() === null) {
        return false;
    }
    return odata_bc_auth_for_fallback([]) !== null;
}

function odata_mimir_log_fallback(Throwable $exception): void
{
    $message = $exception->getMessage();
    $redactions = [];
    $apiKey = odata_mimir_api_key();
    if ($apiKey !== '') {
        $redactions[] = $apiKey;
    }
    global $auth, $auth_list;
    if (isset($auth) && is_array($auth) && isset($auth['pass']) && is_string($auth['pass']) && $auth['pass'] !== '') {
        $redactions[] = $auth['pass'];
    }
    if (isset($auth_list) && is_array($auth_list)) {
        foreach ($auth_list as $entry) {
            if (is_array($entry) && isset($entry['pass']) && is_string($entry['pass']) && $entry['pass'] !== '') {
                $redactions[] = $entry['pass'];
            }
        }
    }
    foreach ($redactions as $secret) {
        $message = str_replace($secret, '[redacted]', $message);
    }
    $sanitized = preg_replace('/(Bearer\s+)\S+/i', '$1[redacted]', $message);
    if (is_string($sanitized)) {
        $message = $sanitized;
    }
    error_log('[Janus] Mímir failed, falling back to direct OData: ' . $message);
}

/**
 * @param callable $viaMimir
 * @param callable $viaDirect
 * @return mixed
 */
function odata_mimir_or_direct(callable $viaMimir, callable $viaDirect)
{
    if (odata_mimir_circuit_open()) {
        $original = odata_mimir_last_error();
        if (!odata_bc_credentials_configured()) {
            if ($original instanceof Throwable) {
                throw $original;
            }
            throw new Exception('Mímir eerder mislukt.');
        }
        return $viaDirect();
    }

    try {
        return $viaMimir();
    } catch (Throwable $exception) {
        // Alleen odata_mimir_fail() (transport, non-2xx, ongeldige JSON, foutpayload) opent het circuit.
        if (!odata_mimir_circuit_open()) {
            throw $exception;
        }
        if (!odata_bc_credentials_configured()) {
            throw $exception;
        }
        odata_mimir_log_fallback($exception);
        return $viaDirect();
    }
}

function odata_bc_url_from_odata_url(string $url): string
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return $url;
    }
    $host = strtolower((string) ($parts['host'] ?? ''));
    if ($host !== 'mimir.invalid') {
        return $url;
    }
    $base = odata_bc_base_url();
    $env = odata_bc_environment_from_odata_url($url);
    if ($base === null || $env === null) {
        return $url;
    }
    $path = (string) ($parts['path'] ?? '');
    if (preg_match('#^/[^/]+(/.+)$#', $path, $match) !== 1) {
        return $url;
    }
    $rebuilt = rtrim($base, '/') . '/' . rawurlencode($env) . $match[1];
    if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
        $rebuilt .= '?' . $parts['query'];
    }
    return $rebuilt;
}

/**
 * Directe BC-companylijst via de pre-Mímir OData-route ({base}/{env}/ODataV4/Company).
 *
 * @return list<array<string, mixed>>
 */
function odata_direct_companies_as_rows(?string $environmentFilter = null): array
{
    odata_ensure_bc_config_loaded();
    $envs = odata_bc_environment_list($environmentFilter);
    $base = odata_bc_base_url();
    if ($base === null || $envs === []) {
        $previous = odata_mimir_last_error();
        if ($previous instanceof Throwable) {
            throw $previous;
        }
        throw new Exception('Mímir mislukt.');
    }

    $out = [];
    $attempted = false;
    foreach ($envs as $env) {
        $auth = odata_bc_auth_for_company_env($env, []);
        if ($auth === null) {
            continue;
        }
        $attempted = true;
        $url = rtrim($base, '/') . '/' . rawurlencode($env) . '/ODataV4/Company';
        $rows = odata_get_all_direct($url, $auth, 300);
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['Name'] ?? $row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $out[] = ['Name' => $name, 'environment' => $env];
        }
    }
    if (!$attempted) {
        $previous = odata_mimir_last_error();
        if ($previous instanceof Throwable) {
            throw $previous;
        }
        throw new Exception('Mímir mislukt.');
    }
    return $out;
}

/**
 * Zelfde company/table-query, maar via de pre-Mímir BC-URL en filecache.
 *
 * @param array<string, mixed> $odataQuery
 * @return list<array<string, mixed>>
 */
function odata_direct_query(string $company, string $table, array $odataQuery, int $ttlSeconds): array
{
    odata_ensure_bc_config_loaded();
    $env = odata_bc_environment_for_company($company);
    $base = odata_bc_base_url();
    $auth = $env !== null ? odata_bc_auth_for_company_env($env, []) : null;
    if ($env === null || $base === null || $auth === null) {
        $previous = odata_mimir_last_error();
        if ($previous instanceof Throwable) {
            throw $previous;
        }
        throw new Exception('Mímir mislukt.');
    }

    $params = [];
    foreach (['$select', '$filter', '$orderby', '$expand', '$top', '$skip', 'select', 'filter'] as $key) {
        if (!array_key_exists($key, $odataQuery)) {
            continue;
        }
        $value = trim((string) $odataQuery[$key]);
        if ($value === '') {
            continue;
        }
        $odataKey = ($key === 'select' || $key === 'filter') ? ('$' . $key) : $key;
        $params[$odataKey] = $value;
    }

    $url = rtrim($base, '/') . '/' . rawurlencode($env) . "/ODataV4/Company('" . rawurlencode($company) . "')/" . rawurlencode($table);
    if ($params !== []) {
        $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    return odata_get_all_direct($url, $auth, $ttlSeconds);
}

function odata_direct_cache_environment(string $url): string
{
    $env = odata_bc_environment_from_odata_url($url);
    if (is_string($env) && $env !== '' && strcasecmp($env, 'mimir') !== 0) {
        return $env;
    }
    $primary = odata_bc_environment();
    if ($primary !== null && strcasecmp($primary, 'mimir') !== 0) {
        return $primary;
    }
    return '';
}


/**
 * @return list<array<string, mixed>>
 */
function odata_fallback_fetch_url(string $url, int $ttlSeconds): array
{
    $env = odata_bc_environment_from_odata_url($url);
    $auth = odata_bc_auth_for_company_env($env, []);
    if ($auth === null) {
        $previous = odata_mimir_last_error();
        if ($previous instanceof Throwable) {
            throw $previous;
        }
        throw new Exception('Mímir mislukt.');
    }
    return odata_get_all_direct(odata_bc_url_from_odata_url($url), $auth, $ttlSeconds);
}

/**
 * @return list<array<string, mixed>>
 */
function odata_fallback_fetch_with_auth(string $url, array $auth, $ttlSeconds): array
{
    $env = odata_bc_environment_from_odata_url($url);
    $directAuth = odata_bc_auth_for_company_env($env, $auth) ?? $auth;
    return odata_get_all_direct(odata_bc_url_from_odata_url($url), $directAuth, $ttlSeconds);
}
