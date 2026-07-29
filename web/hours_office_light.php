<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/localization.php';
require_once __DIR__ . '/hours_data.php';

function office_light_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function office_light_parse_ymd(?string $value): ?DateTimeImmutable
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
    office_light_json(['ok' => false, 'error' => 'Geen gebruiker in sessie.'], 403);
}
auth_require_page_access('urentracker');

$today = new DateTimeImmutable('today');
$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'setup') {
    $defaults = hours_default_office_days_from_post($_POST);
    $userName = hours_current_user_name();
    $existing = hours_load_existing($email);
    if ($existing !== null) {
        $data = $existing;
    } else {
        $data = hours_default_save_data($userName, $email);
    }
    $data['DefaultOfficeDays'] = $defaults;
    $data['UserName'] = hours_resolve_user_name($data, $email);
    $data['UserEmail'] = $email;

    try {
        hours_save($email, $data);
        janus_set_light_setup_done($email, true);
        janus_set_light_mode($email, true);
    } catch (Throwable $e) {
        office_light_json(['ok' => false, 'error' => 'Opslaan mislukt.'], 500);
    }

    office_light_json([
        'ok' => true,
        'setup' => true,
        'DefaultOfficeDays' => $defaults,
    ]);
}

if (janus_needs_light_setup($email)) {
    office_light_json(['ok' => false, 'error' => 'Setup vereist.', 'needsSetup' => true], 409);
}

$data = hours_load($email, hours_current_user_name());

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $year = (int) ($_GET['year'] ?? (int) $today->format('Y'));
    $month = (int) ($_GET['month'] ?? (int) $today->format('n'));
    if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
        office_light_json(['ok' => false, 'error' => 'Ongeldige maand.'], 422);
    }

    $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
    $daysInMonth = (int) $first->format('t');
    $startWeekday = hours_weekday_index($first);
    $defaultDays = hours_get_default_office_days($data);

    $days = [];
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $date = $first->setDate($year, $month, $d);
        $weekday = hours_weekday_index($date);
        $manual = hours_day_exists($data, $date);
        $office = hours_is_effective_office_day($data, $date);
        $days[] = [
            'day' => $d,
            'iso' => $date->format('Y-m-d'),
            'weekday' => $weekday,
            'office' => $office,
            'manual' => $manual,
            'off' => !$defaultDays[$weekday],
            'today' => $date->format('Y-m-d') === $today->format('Y-m-d'),
        ];
    }

    office_light_json([
        'ok' => true,
        'year' => $year,
        'month' => $month,
        'label' => janus_nl_month_abbrev($month) . ' ' . $year,
        'startWeekday' => $startWeekday,
        'DefaultOfficeDays' => $defaultDays,
        'days' => $days,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $day = office_light_parse_ymd($_POST['day'] ?? null);
    if (!$day) {
        office_light_json(['ok' => false, 'error' => 'Ongeldige dag.'], 422);
    }

    $enable = !empty($_POST['office']);
    $key = hours_day_key($day);
    $defaultOffice = hours_default_office_for_weekday($data, hours_weekday_index($day));

    if ($enable === $defaultOffice) {
        // Follow weekday default again: remove light stubs, else set flag to match.
        if (hours_day_exists($data, $day)) {
            $row = hours_get_day($data, $day);
            if (hours_is_office_light_stub($row)) {
                hours_delete_day($data, $day);
            } else {
                $row['HomeWorkDriven'] = $enable;
                if ($enable) {
                    $row['isHoliday'] = false;
                    $row['isSickDay'] = false;
                }
                $data['SavedDays'][$key] = hours_enrich_day_data($row);
            }
        }
    } elseif ($enable) {
        if (hours_day_exists($data, $day)) {
            $row = hours_get_day($data, $day);
            $row['HomeWorkDriven'] = true;
            $row['isHoliday'] = false;
            $row['isSickDay'] = false;
            $data['SavedDays'][$key] = hours_enrich_day_data($row);
        } else {
            $data['SavedDays'][$key] = hours_office_light_day_data(true);
        }
    } else {
        if (hours_day_exists($data, $day)) {
            $row = hours_get_day($data, $day);
            if (hours_is_office_light_stub($row)) {
                // Keep an explicit home override against a default office weekday.
                $row['HomeWorkDriven'] = false;
                $data['SavedDays'][$key] = hours_enrich_day_data($row);
            } else {
                $row['HomeWorkDriven'] = false;
                $data['SavedDays'][$key] = hours_enrich_day_data($row);
            }
        } else {
            $data['SavedDays'][$key] = hours_office_light_day_data(false);
        }
    }

    hours_calculate_extra_seconds($data, $day, false);
    hours_calculate_extra_seconds($data, $day, true);

    try {
        hours_save($email, $data);
    } catch (Throwable $e) {
        office_light_json(['ok' => false, 'error' => 'Opslaan mislukt.'], 500);
    }

    office_light_json([
        'ok' => true,
        'day' => $day->format('Y-m-d'),
        'office' => hours_is_effective_office_day($data, $day),
    ]);
}

office_light_json(['ok' => false, 'error' => 'Method not allowed.'], 405);
