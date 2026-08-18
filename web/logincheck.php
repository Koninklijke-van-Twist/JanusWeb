<?php

function is_trusted_requester(): bool
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    $server = $_SERVER['SERVER_ADDR'] ?? '';
    $trusted = ['127.0.0.1', '::1'];
    if ($remote === $server && $remote !== '') {
        return true;
    }
    if (in_array($remote, $trusted, true)) {
        return true;
    }
    return false;
}

if (is_trusted_requester()) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }

    $_SESSION['user'] = [];
    $_SESSION['user']['email'] = "testuser@kvt.nl";

    $currentEmail = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
    if(isset($allowedUsers))
    {
        $defaultAllowedUser = strtolower(trim((string) ($allowedUsers[0] ?? '')));
        if ($currentEmail === '' && $defaultAllowedUser !== '') {
            if (!is_array($_SESSION['user'] ?? null)) {
                $_SESSION['user'] = [];
            }

            $_SESSION['user']['email'] = $defaultAllowedUser;
        }
    }
}

if (!is_trusted_requester()) {
    require __DIR__ . "/../login/lib.php";

    if ( isset($allowedUsers) &&
        !array_any($allowedUsers, function ($email) {
            return strtolower((string) $email) === strtolower((string) ($_SESSION['user']['email'] ?? ''));
        })
    ) {
        require __DIR__ . "/../login/403.php";
        die();
    }

    $analyticsEmail = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
    $analyticsApiKey = trim((string) ($_SESSION['user']['api_key'] ?? ''));
    $analyticsOid = strtolower(trim((string) ($_SESSION['user']['oid'] ?? '')));
    $analyticsLog = [
        'ok' => false,
        'reason' => 'missing_session_fields',
        'has_email' => $analyticsEmail !== '',
        'has_api_key' => $analyticsApiKey !== '',
        'has_oid' => $analyticsOid !== '',
    ];

    if ($analyticsEmail !== '' && $analyticsApiKey !== '' && $analyticsOid !== '') {
        $analyticsScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $analyticsHost = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $analyticsBase = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
        $analyticsUrl = $analyticsScheme . '://' . $analyticsHost . $analyticsBase . '/analytics/analytics.php?' . http_build_query([
            'user_email' => $analyticsEmail,
            'api_key' => $analyticsApiKey,
            'oid' => $analyticsOid,
        ], '', '&', PHP_QUERY_RFC3986);
        $analyticsLogUrl = preg_replace('/([?&]api_key=)[^&]*/', '$1…', $analyticsUrl);

        if (!function_exists('curl_init')) {
            $analyticsLog = [
                'ok' => false,
                'reason' => 'curl_missing',
                'url' => $analyticsLogUrl,
            ];
        } else {
            try {
                $analyticsCurl = curl_init($analyticsUrl);
                curl_setopt_array($analyticsCurl, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 1,
                    CURLOPT_TIMEOUT => 1,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_HTTPHEADER => ['X-API-Key: ' . $analyticsApiKey],
                ]);
                $analyticsBody = curl_exec($analyticsCurl);
                $analyticsErrno = curl_errno($analyticsCurl);
                $analyticsError = curl_error($analyticsCurl);
                $analyticsStatus = (int) curl_getinfo($analyticsCurl, CURLINFO_HTTP_CODE);
                curl_close($analyticsCurl);

                $analyticsDecoded = is_string($analyticsBody) ? json_decode($analyticsBody, true) : null;
                $analyticsOk = $analyticsBody !== false
                    && $analyticsErrno === 0
                    && $analyticsStatus >= 200
                    && $analyticsStatus < 300
                    && is_array($analyticsDecoded)
                    && !empty($analyticsDecoded['ok']);

                $analyticsLog = [
                    'ok' => $analyticsOk,
                    'url' => $analyticsLogUrl,
                    'http' => $analyticsStatus,
                    'curl_errno' => $analyticsErrno,
                    'curl_error' => $analyticsError,
                    'body' => $analyticsBody === false ? null : $analyticsBody,
                ];
            } catch (Throwable $analyticsException) {
                $analyticsLog = [
                    'ok' => false,
                    'reason' => 'exception',
                    'url' => $analyticsLogUrl,
                    'error' => $analyticsException->getMessage(),
                    'stack' => $analyticsException->getTraceAsString(),
                ];
            }
        }
    }

    janus_emit_analytics_console($analyticsLog);
}

function janus_emit_analytics_console(array $info): void
{
    $script = strtolower(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
    if ($script === '' || str_contains($script, '_api') || str_contains($script, '_save') || str_contains($script, 'excel')) {
        return;
    }
    if (in_array($script, ['tracker_mode.php', 'export.php'], true)) {
        return;
    }

    $json = json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
    echo '<script>(function(){',
        'const info = ', $json, ';',
        'if (info && info.ok) {',
        'console.log("analytics: success", info);',
        '} else {',
        'const err = new Error((info && info.error) ? String(info.error) : "analytics: failed");',
        'if (info && info.stack) { err.stack = String(info.stack); }',
        'console.error("analytics: error", info, err.stack);',
        '}',
        '})();</script>', "\n";
}