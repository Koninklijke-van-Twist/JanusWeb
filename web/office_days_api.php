<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/hours_data.php';

function office_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function office_parse_date(?string $value): ?DateTimeImmutable
{
    $value = trim((string) $value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    return $dt instanceof DateTimeImmutable ? $dt : null;
}

if (hours_current_user_email() === '') {
    office_json(['ok' => false, 'error' => 'Geen gebruiker in sessie.'], 403);
}
auth_require_page_access('kantoordagen');

$today = new DateTimeImmutable('today');
$start = office_parse_date($_GET['start'] ?? null) ?? $today->modify('first day of this month');
$end = office_parse_date($_GET['end'] ?? null) ?? $today;
if ($end < $start) {
    $end = $start;
}

$rows = [];
foreach (auth_users_with_access('urentracker') as $email) {
    $data = hours_load($email, '');
    $name = hours_resolve_user_name($data, $email);
    $missing = hours_missing_days_in_range($data, $start, $end);
    $missingLabels = [];
    foreach ($missing as $iso) {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', (string) $iso);
        if ($dt instanceof DateTimeImmutable) {
            $missingLabels[] = janus_nl_date_ui($dt);
        }
    }
    $rows[] = [
        'email' => $email,
        'name' => $name,
        'officeDays' => hours_count_office_days_in_range($data, $start, $end),
        'missingDays' => $missingLabels,
        'warning' => $missingLabels === [] ? '' : ('Ontbrekende dagen: ' . implode(', ', $missingLabels)),
    ];
}

usort($rows, static function (array $a, array $b): int {
    return strcasecmp($a['name'], $b['name']) ?: strcasecmp($a['email'], $b['email']);
});

office_json([
    'ok' => true,
    'start' => $start->format('Y-m-d'),
    'end' => $end->format('Y-m-d'),
    'startLabel' => janus_nl_date_ui($start),
    'endLabel' => janus_nl_date_ui($end),
    'rows' => $rows,
]);
