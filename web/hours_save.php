<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/hours_data.php';

function save_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function save_parse_day(?string $value): ?DateTimeImmutable
{
    $value = trim((string) $value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    return $dt instanceof DateTimeImmutable ? $dt : null;
}

function save_normalize_time(string $value): string
{
    if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($value), $m) !== 1) {
        return '00:00:00';
    }

    return sprintf('%02d:%02d:00', min(23, (int) $m[1]), min(59, (int) $m[2]));
}

$email = hours_current_user_email();
if ($email === '') {
    save_json(['ok' => false, 'error' => 'Geen gebruiker in sessie.'], 403);
}
auth_require_page_access('urentracker');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    save_json(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

$day = save_parse_day($_POST['day'] ?? null);
if (!$day) {
    save_json(['ok' => false, 'error' => 'Ongeldige dag.'], 422);
}

$data = hours_load($email, hours_current_user_name());
$key = hours_day_key($day);
hours_ensure_day_initialized($data, $day);
$row = $data['SavedDays'][$key];

$isHoliday = !empty($_POST['isHoliday']);
$isSickDay = !empty($_POST['isSickDay']);
if ($isHoliday && $isSickDay) {
    $isSickDay = false;
}

if ($isHoliday || $isSickDay) {
    $row['StartTime'] = '00:00:00';
    $row['EndTime'] = '00:00:00';
    $row['BreakMinutes'] = 0;
    $row['Kilometers'] = 0.0;
    $row['HomeWorkDriven'] = false;
    $row['isHoliday'] = $isHoliday;
    $row['isSickDay'] = $isSickDay;
} else {
    $start = save_normalize_time((string) ($_POST['startTime'] ?? '09:00'));
    $end = save_normalize_time((string) ($_POST['endTime'] ?? '17:30'));
    if (hours_parse_timespan($end) < hours_parse_timespan($start)) {
        $end = $start;
    }

    $row['StartTime'] = $start;
    $row['EndTime'] = $end;
    $row['BreakMinutes'] = max(0, (int) ($_POST['breakMinutes'] ?? 0));
    $row['Kilometers'] = max(0, (float) str_replace(',', '.', (string) ($_POST['kilometers'] ?? 0)));
    $row['HomeWorkDriven'] = !empty($_POST['homeWorkDriven']);
    $row['isHoliday'] = false;
    $row['isSickDay'] = false;
}

$data['SavedDays'][$key] = hours_enrich_day_data($row);
hours_calculate_extra_seconds($data, $day, false);
hours_calculate_extra_seconds($data, $day, true);
hours_save($email, $data);

save_json([
    'ok' => true,
    'workedString' => $data['SavedDays'][$key]['WorkedString'],
    'workedTime' => $data['SavedDays'][$key]['WorkedTime'],
]);
