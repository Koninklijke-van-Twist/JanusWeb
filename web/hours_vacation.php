<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/hours_data.php';

function vacation_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function vacation_parse_ymd(?string $value): ?DateTimeImmutable
{
    $value = trim((string) $value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);

    return $dt instanceof DateTimeImmutable ? $dt : null;
}

$email = hours_current_user_email();
if ($email === '') {
    vacation_json(['ok' => false, 'error' => 'Geen gebruiker in sessie.'], 403);
}
auth_require_page_access('urentracker');

$data = hours_load($email, hours_current_user_name());
$today = new DateTimeImmutable('today');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $year = (int) ($_GET['year'] ?? (int) $today->format('Y'));
    $month = (int) ($_GET['month'] ?? (int) $today->format('n'));
    if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
        vacation_json(['ok' => false, 'error' => 'Ongeldige maand.'], 422);
    }

    $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
    $daysInMonth = (int) $first->format('t');
    $startWeekday = hours_weekday_index($first); // Mon=0

    $contractZero = [];
    for ($i = 0; $i < 7; $i++) {
        $contractZero[$i] = hours_get_work_hours_seconds($data, $i) <= 0;
    }

    $days = [];
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $date = $first->setDate($year, $month, $d);
        $weekday = hours_weekday_index($date);
        $isHoliday = false;
        if (hours_day_exists($data, $date)) {
            $row = hours_get_day($data, $date);
            $isHoliday = !empty($row['isHoliday']);
        }
        $days[] = [
            'day' => $d,
            'iso' => $date->format('Y-m-d'),
            'weekday' => $weekday,
            'holiday' => $isHoliday,
            'off' => !empty($contractZero[$weekday]),
            'today' => $date->format('Y-m-d') === $today->format('Y-m-d'),
        ];
    }

    vacation_json([
        'ok' => true,
        'year' => $year,
        'month' => $month,
        'label' => janus_nl_month_abbrev($month) . ' ' . $year,
        'startWeekday' => $startWeekday,
        'days' => $days,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $day = vacation_parse_ymd($_POST['day'] ?? null);
    if (!$day) {
        vacation_json(['ok' => false, 'error' => 'Ongeldige dag.'], 422);
    }

    $enable = !empty($_POST['holiday']);
    $key = hours_day_key($day);

    if ($enable) {
        $data['SavedDays'][$key] = hours_vacation_day_data();
    } else {
        hours_delete_day($data, $day);
    }

    hours_calculate_extra_seconds($data, $day, false);
    hours_calculate_extra_seconds($data, $day, true);

    try {
        hours_save($email, $data);
    } catch (Throwable $e) {
        vacation_json(['ok' => false, 'error' => 'Opslaan mislukt.'], 500);
    }

    vacation_json([
        'ok' => true,
        'day' => $day->format('Y-m-d'),
        'holiday' => $enable,
    ]);
}

vacation_json(['ok' => false, 'error' => 'Method not allowed.'], 405);
