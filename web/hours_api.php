<?php
/**
 * Read-only hours/vacation status API for sibling apps (Asclepius).
 * Does not use logincheck.php (avoids trusted-local testuser injection).
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/hours_data.php';

function hours_api_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function hours_api_is_trusted_requester(): bool
{
    $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $server = trim((string) ($_SERVER['SERVER_ADDR'] ?? ''));
    if ($remote !== '' && $remote === $server) {
        return true;
    }

    return in_array($remote, ['127.0.0.1', '::1'], true);
}

function hours_api_get_api_key(): string
{
    $headerKey = '';
    if (isset($_SERVER['HTTP_X_API_KEY'])) {
        $headerKey = (string) $_SERVER['HTTP_X_API_KEY'];
    } elseif (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strtolower((string) $name) === 'x-api-key') {
                $headerKey = (string) $value;
                break;
            }
        }
    }

    if ($headerKey !== '') {
        return trim($headerKey);
    }

    return trim((string) ($_GET['api_key'] ?? $_POST['api_key'] ?? ''));
}

function hours_api_authorize(): bool
{
    if (hours_api_is_trusted_requester()) {
        return true;
    }

    $apiKey = hours_api_get_api_key();
    $oid = strtolower(trim((string) ($_GET['oid'] ?? $_POST['oid'] ?? $_SERVER['HTTP_X_OID'] ?? '')));
    if ($apiKey === '' || $oid === '') {
        return false;
    }

    $sessionUserPath = __DIR__ . '/../login/session_user.php';
    if (!is_file($sessionUserPath)) {
        return false;
    }

    require_once $sessionUserPath;

    return function_exists('verify_rotating_api_key') && verify_rotating_api_key($oid, $apiKey);
}

function hours_api_parse_date(?string $value): ?DateTimeImmutable
{
    $value = trim((string) $value);
    if ($value === '') {
        return new DateTimeImmutable('today');
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    return $dt instanceof DateTimeImmutable ? $dt : null;
}

/**
 * @return list<string>
 */
function hours_api_parse_emails(mixed $raw): array
{
    if (is_array($raw)) {
        $parts = $raw;
    } else {
        $parts = preg_split('/[\s,;]+/', (string) $raw) ?: [];
    }

    $emails = [];
    foreach ($parts as $part) {
        $email = strtolower(trim((string) $part));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[$email] = $email;
        }
    }

    return array_values($emails);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    hours_api_json(['ok' => false, 'error' => 'Methode niet toegestaan.'], 405);
}

if (!hours_api_authorize()) {
    hours_api_json(['ok' => false, 'error' => 'Niet geautoriseerd.'], 401);
}

$date = hours_api_parse_date($_GET['date'] ?? $_POST['date'] ?? null);
if ($date === null) {
    hours_api_json(['ok' => false, 'error' => 'Ongeldige datum.'], 422);
}

$emails = hours_api_parse_emails($_GET['emails'] ?? $_POST['emails'] ?? '');
if ($emails === []) {
    hours_api_json(['ok' => false, 'error' => 'Geen geldige e-mailadressen.'], 422);
}

$users = [];
foreach ($emails as $email) {
    $data = hours_load_existing($email);
    if ($data === null) {
        $users[$email] = [
            'known' => false,
            'holiday' => false,
            'contractOff' => false,
            'locked' => false,
            'reason' => null,
        ];
        continue;
    }

    $users[$email] = hours_day_away_status($data, $date);
}

hours_api_json([
    'ok' => true,
    'date' => $date->format('Y-m-d'),
    'weekday' => strtolower($date->format('l')),
    'users' => $users,
]);
