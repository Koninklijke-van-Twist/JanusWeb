<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/**
 * Includes/requires
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/localization.php';
require_once __DIR__ . '/hours_data.php';

/**
 * Functies
 */

function janus_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function janus_parse_selected_day(?string $value): DateTimeImmutable
{
    $value = trim((string) $value);
    if ($value !== '') {
        $fromKey = hours_parse_day_key($value);
        if ($fromKey instanceof DateTimeImmutable) {
            return $fromKey;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
            if ($dt instanceof DateTimeImmutable) {
                return $dt;
            }
        }
    }

    return new DateTimeImmutable('today');
}

/**
 * @param array<string, mixed> $day
 * @return array<string, mixed>
 */
function janus_apply_day_post(array $day, array $post): array
{
    $isHoliday = !empty($post['isHoliday']);
    $isSickDay = !empty($post['isSickDay']);
    if ($isHoliday && $isSickDay) {
        $isSickDay = false;
    }

    if ($isHoliday || $isSickDay) {
        $day['StartTime'] = '00:00:00';
        $day['EndTime'] = '00:00:00';
        $day['BreakMinutes'] = 0;
        $day['Kilometers'] = 0.0;
        $day['HomeWorkDriven'] = false;
        $day['isHoliday'] = $isHoliday;
        $day['isSickDay'] = $isSickDay;

        return hours_enrich_day_data($day);
    }

    $start = janus_normalize_time_input((string) ($post['startTime'] ?? '09:00'));
    $end = janus_normalize_time_input((string) ($post['endTime'] ?? '17:30'));
    if (hours_parse_timespan($end) < hours_parse_timespan($start)) {
        $end = $start;
    }

    $day['StartTime'] = $start;
    $day['EndTime'] = $end;
    $day['BreakMinutes'] = max(0, (int) ($post['breakMinutes'] ?? 0));
    $day['Kilometers'] = max(0, (float) str_replace(',', '.', (string) ($post['kilometers'] ?? 0)));
    $day['HomeWorkDriven'] = !empty($post['homeWorkDriven']);
    $day['isHoliday'] = false;
    $day['isSickDay'] = false;

    return hours_enrich_day_data($day);
}

function janus_normalize_time_input(string $value): string
{
    $value = trim($value);
    if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $m) !== 1) {
        return '00:00:00';
    }

    $h = min(23, max(0, (int) $m[1]));
    $min = min(59, max(0, (int) $m[2]));

    return sprintf('%02d:%02d:00', $h, $min);
}

function janus_format_day_header(DateTimeImmutable $day, array $dayData): string
{
    $formatted = janus_nl_date_ui($day);

    if (!empty($dayData['isHoliday'])) {
        return $formatted . ' - Vakantiedag';
    }
    if (!empty($dayData['isSickDay'])) {
        return $formatted . ' - Ziek';
    }

    $workedSeconds = hours_parse_timespan((string) $dayData['WorkedTime']);
    $h = intdiv(abs($workedSeconds), 3600);
    $m = intdiv(abs($workedSeconds) % 3600, 60);
    $minuteLabel = $m === 1 ? 'minuut' : 'minuten';

    return sprintf('%s - %d uur, %d %s gewerkt', $formatted, $h, $m, $minuteLabel);
}

/**
 * @param array<string, mixed> $data
 * @return list<array{date: DateTimeImmutable, label: string, hours: string, delta: string, deltaClass: string, selected: bool}>
 */
function janus_week_strip(array $data, DateTimeImmutable $selectedDay): array
{
    $dayNumber = hours_weekday_index($selectedDay);
    $monday = $selectedDay->modify('-' . $dayNumber . ' days');
    $names = ['Ma', 'Di', 'Wo', 'Do', 'Vr', 'Za', 'Zo'];
    $strip = [];

    for ($i = 0; $i < 7; $i++) {
        $day = $monday->modify('+' . $i . ' days');
        $contract = hours_get_work_hours_seconds($data, $i);
        $entry = [
            'date' => $day,
            'label' => $names[$i],
            'hours' => '',
            'delta' => '',
            'deltaClass' => 'delta-empty',
            'selected' => $day->format('Y-m-d') === $selectedDay->format('Y-m-d'),
        ];

        if (hours_day_exists($data, $day)) {
            $dayData = hours_get_day($data, $day);
            if (!empty($dayData['isHoliday'])) {
                $entry['hours'] = 'VAKANTIE';
                $minutes = 0;
            } elseif (!empty($dayData['isSickDay'])) {
                $entry['hours'] = 'ZIEK';
                $minutes = 0;
            } else {
                $worked = hours_parse_timespan((string) $dayData['WorkedTime']);
                $entry['hours'] = ($contract <= 0 && $worked <= 0) ? 'VRIJ' : hours_format_hhmm_from_seconds($worked);
                $minutes = (int) round(($worked - $contract) / 60);
            }
            $entry['delta'] = sprintf('%+d', $minutes);
            if ($minutes > 0) {
                $entry['deltaClass'] = 'delta-plus';
            } elseif ($minutes < 0) {
                $entry['deltaClass'] = 'delta-minus';
            } else {
                $entry['deltaClass'] = 'delta-zero';
            }
            if ($minutes === 0 && ($entry['hours'] === 'VRIJ' || $entry['hours'] === 'VAKANTIE')) {
                $entry['delta'] = '';
                $entry['deltaClass'] = 'delta-empty';
            }
        } elseif ($contract <= 0) {
            $entry['hours'] = 'VRIJ';
            $entry['delta'] = '';
            $entry['deltaClass'] = 'delta-empty';
        }

        $strip[] = $entry;
    }

    return $strip;
}

/**
 * Page load
 */

$email = hours_current_user_email();
if ($email === '') {
    http_response_code(403);
    echo 'Geen gebruiker in sessie.';
    exit;
}
auth_require_page_access('urentracker');

if (janus_is_light_mode($email)) {
    require __DIR__ . '/index_light.php';
    exit;
}

$userName = hours_current_user_name();
$data = hours_load($email, $userName);
$userName = hours_resolve_user_name($data, $email);
$data['UserName'] = $userName;
$data['UserEmail'] = $email;

$selectedDay = janus_parse_selected_day($_GET['day'] ?? ($_POST['day'] ?? null));
$today = new DateTimeImmutable('today');
if ($selectedDay > $today) {
    $selectedDay = $today;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'save');
    $postDay = janus_parse_selected_day($_POST['day'] ?? null);
    if ($postDay > $today) {
        $postDay = $today;
    }

    hours_ensure_day_initialized($data, $postDay);
    $dayKey = hours_day_key($postDay);
    $data['SavedDays'][$dayKey] = janus_apply_day_post($data['SavedDays'][$dayKey], $_POST);

    // Refresh overtime ticks for current month context
    hours_calculate_extra_seconds($data, $postDay, false);
    hours_calculate_extra_seconds($data, $postDay, true);

    try {
        hours_save($email, $data);
    } catch (Throwable $e) {
        $error = 'Opslaan mislukt.';
    }

    if ($error === '') {
        if ($action === 'prev') {
            $selectedDay = $postDay->modify('-1 day');
        } elseif ($action === 'prevweek') {
            $selectedDay = $postDay->modify('-7 day');
        } elseif ($action === 'next' && $postDay < $today) {
            $selectedDay = $postDay->modify('+1 day');
        } elseif ($action === 'nextweek') {
            $selectedDay = $postDay->modify('+7 day');
            if ($selectedDay > $today) {
                $selectedDay = $today;
            }
        } elseif ($action === 'goto') {
            $selectedDay = janus_parse_selected_day($_POST['goto_day'] ?? null);
            if ($selectedDay > $today) {
                $selectedDay = $today;
            }
        } else {
            $selectedDay = $postDay;
            $message = 'Opgeslagen.';
        }
    } else {
        $selectedDay = $postDay;
    }
}

$selectedDayWasNew = !hours_day_exists($data, $selectedDay);
if ($selectedDayWasNew) {
    hours_backfill_previous_missing_days($data, $selectedDay);
}
$dayData = hours_ensure_day_initialized($data, $selectedDay);
$weekStrip = janus_week_strip($data, $selectedDay);
$extra = hours_calculate_extra_seconds($data, $selectedDay, false);
$extraPrev = hours_calculate_extra_seconds($data, $selectedDay, true);
$extraTotal = $extra + $extraPrev;

$contractSeconds = hours_get_work_hours_seconds($data, hours_weekday_index($selectedDay));
$selectedWorkedSeconds = hours_parse_timespan((string) ($dayData['WorkedTime'] ?? '00:00:00'));
$selectedExtraContribution = 0;
if (
    ($contractSeconds > 0 || $selectedWorkedSeconds > 0)
    && empty($dayData['isHoliday'])
    && empty($dayData['isSickDay'])
    && $selectedDay <= $today
) {
    $selectedExtraContribution = $selectedWorkedSeconds - $contractSeconds;
}
$extraBaseWithoutSelected = $extra - $selectedExtraContribution;

// Persist refreshed MonthExtraTicks
try {
    hours_save($email, $data);
} catch (Throwable $e) {
    // keep UI usable even if write fails after calc
}

$canGoNext = $selectedDay < $today;
$canGoNextWeek = $selectedDay->modify('+7 day') <= $today;
$timeDisabled = !empty($dayData['isHoliday']) || !empty($dayData['isSickDay']);
$monthLabel = sprintf(
    '%s %d',
    janus_nl_month_abbrev((int) $selectedDay->format('n')),
    (int) $selectedDay->format('Y')
);
$canAccessOfficeDays = auth_can_access_page('kantoordagen');
$canAccessOverview = auth_can_access_page('overzicht');

$startValue = substr((string) $dayData['StartTime'], 0, 5);
$endValue = substr((string) $dayData['EndTime'], 0, 5);
$headerText = janus_format_day_header($selectedDay, $dayData);

$headerDateLabel = janus_nl_date_ui($selectedDay);
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Janus</title>
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
    <link rel="manifest" href="site.webmanifest">
    <link rel="stylesheet" href="brand.css">
    <style>
        :root {
            --janus-selected: #68c7e7;
            --janus-panel: #f0f0f0;
            --janus-plus: #90ee90;
            --janus-minus: #db7093;
            --janus-zero: #d3d3d3;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--kvt-page-bg);
            color: var(--kvt-text);
        }
        .wrap {
            max-width: 720px;
            margin: 0 auto;
            padding: 12px 14px 28px;
        }
        .topbar {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }
        .brand-row {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .brand-row img.logo {
            height: 36px;
            width: auto;
        }
        .brand-row h1 {
            margin: 0;
            font-size: 1.35rem;
            color: var(--kvt-perkins-blue);
        }
        .toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .btn, button, .link-btn {
            appearance: none;
            border: 1px solid var(--kvt-line);
            background: #fff;
            color: var(--kvt-text);
            border-radius: 4px;
            padding: 7px 10px;
            font-size: 0.9rem;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-primary {
            background: var(--kvt-main-blue);
            border-color: var(--kvt-main-blue);
            color: #fff;
        }
        .btn:disabled, button:disabled {
            opacity: 0.45;
            cursor: not-allowed;
        }
        .day-nav {
            display: grid;
            grid-template-columns: 44px 44px 1fr 44px 44px;
            gap: 8px;
            align-items: center;
            margin: 10px 0 14px;
        }
        .day-header {
            appearance: none;
            border: 0;
            background: transparent;
            width: 100%;
            text-align: center;
            font-weight: 700;
            font-size: 0.95rem;
            line-height: 1.35;
            cursor: pointer;
        }
        .panel {
            border: 1px solid var(--kvt-line);
            border-radius: 6px;
            padding: 12px;
            background: #fff;
            margin-bottom: 12px;
        }
        .time-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        label {
            display: block;
            font-size: 0.82rem;
            color: var(--kvt-muted);
            margin-bottom: 4px;
        }
        input[type="time"],
        input[type="number"],
        input[type="date"] {
            width: 100%;
            padding: 8px;
            border: 1px solid var(--kvt-line);
            border-radius: 4px;
            font-size: 1rem;
        }
        .shortcuts {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 8px;
        }
        .flags {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-top: 12px;
        }
        .flag {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px;
            border: 1px solid var(--kvt-line);
            border-radius: 4px;
            background: var(--janus-panel);
        }
        .flag input { width: auto; }
        .flag span { font-weight: 700; font-size: 0.9rem; }
        .flags .flag-btn {
            width: 100%;
            min-height: 42px;
            justify-content: center;
            font-weight: 700;
            background: var(--janus-panel);
            margin-bottom: 4px;
        }
        .km-row {
            margin-top: 10px;
        }
        .week-strip {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 4px;
            margin-top: 4px;
        }
        .week-day {
            text-align: center;
            border-radius: 4px;
            overflow: hidden;
            border: 1px solid var(--kvt-line);
            background: var(--janus-panel);
            padding: 0;
            cursor: pointer;
            color: inherit;
            font: inherit;
        }
        .week-day.selected .name { background: var(--janus-selected); }
        .week-day .name,
        .week-day .hours,
        .week-day .delta {
            display: inline-block;
            padding: 4px 2px;
            font-size: 0.75rem;
            line-height: 1.2;
        }
        .week-day .name,
        .week-day .hours {
            display: block;
        }
        .week-day .delta {
            position: relative;
            z-index: 0;
        }
        .week-day .delta::after {
            content: "";
            position: absolute;
            top: 0;
            left: 100%;
            bottom: 0;
            width: 999px;
            z-index: -1;
        }
        .week-day .name { font-weight: 700; background: var(--janus-panel); }
        .delta-plus,
        .delta-plus::after { background: var(--janus-plus); }
        .delta-minus,
        .delta-minus::after { background: var(--janus-minus); }
        .delta-zero,
        .delta-zero::after { background: var(--janus-zero); }
        .delta-empty,
        .delta-empty::after { background: var(--janus-panel); }
        .delta-empty { min-height: 1.2em; min-width: 1.5em; }
        .overtime {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 12px;
            align-items: end;
            margin-top: 12px;
        }
        .overtime-meta {
            display: grid;
            gap: 4px;
            text-align: left;
        }
        .overtime-total {
            text-align: right;
            align-self: stretch;
            display: flex;
            align-items: center;
        }
        .overtime .muted { color: var(--kvt-muted); font-size: 0.85rem; }
        .overtime .plus { color: #006400; font-weight: 700; }
        .overtime .minus { color: #8b0000; font-weight: 700; }
        .overtime .zero { color: #111; font-weight: 700; }
        .overtime .total { font-size: 1.2rem; }
        .flash {
            padding: 8px 10px;
            border-radius: 4px;
            margin-bottom: 10px;
            font-size: 0.9rem;
        }
        .flash-ok { background: #e8f7ee; color: #146c2e; }
        .flash-err { background: #fdecec; color: #b42318; }
        .footer-meta {
            margin-top: 16px;
            font-size: 0.8rem;
            color: var(--kvt-muted);
        }
        .export-menu {
            position: relative;
            display: inline-block;
        }
        .export-menu details summary {
            list-style: none;
        }
        .export-menu details summary::-webkit-details-marker { display: none; }
        .export-menu .menu {
            position: absolute;
            right: 0;
            top: 100%;
            margin-top: 4px;
            background: #fff;
            border: 1px solid var(--kvt-line);
            border-radius: 4px;
            min-width: 200px;
            z-index: 5;
            box-shadow: 0 4px 12px rgba(0,0,0,.08);
        }
        .export-menu .menu a,
        .export-menu .menu button {
            display: block;
            width: 100%;
            text-align: left;
            border: 0;
            border-radius: 0;
            background: transparent;
            padding: 8px 10px;
        }
        .export-menu .menu a:hover,
        .export-menu .menu button:hover {
            background: #f3f7fb;
        }
        .pdf-modal {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.55);
            display: none;
            align-items: stretch;
            justify-content: center;
            z-index: 2000;
            padding: 10px;
        }
        .pdf-modal.open { display: flex; }
        .pdf-modal-dialog {
            width: min(1200px, 100%);
            background: #fff;
            border-radius: 8px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            box-shadow: 0 20px 40px rgba(0,0,0,.3);
        }
        .pdf-modal-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding: 8px;
            border-bottom: 1px solid var(--kvt-line);
            background: #f8fbff;
        }
        .pdf-modal-body {
            padding: 10px;
            overflow: auto;
            background: #e9edf2;
            min-height: 70vh;
        }
        .pdf-preview-content {
            margin: 0 auto;
            width: fit-content;
            max-width: 100%;
            background: #fff;
        }
        .range-modal {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.45);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2100;
            padding: 10px;
        }
        .range-modal.open { display: flex; }
        .range-modal-card {
            width: min(420px, 100%);
            background: #fff;
            border-radius: 8px;
            padding: 14px;
            box-shadow: 0 20px 40px rgba(0,0,0,.3);
        }
        .range-modal-actions {
            display: flex;
            gap: 8px;
            margin-top: 12px;
            justify-content: flex-end;
        }
        .vacation-cal-card {
            width: min(420px, 100%);
        }
        .vacation-cal-nav {
            display: grid;
            grid-template-columns: 44px 1fr 44px;
            gap: 8px;
            align-items: center;
            margin-bottom: 12px;
        }
        .vacation-cal-nav .month-label {
            text-align: center;
            font-weight: 700;
            color: var(--kvt-perkins-blue);
            text-transform: capitalize;
        }
        .vacation-cal-weekdays,
        .vacation-cal-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 6px;
        }
        .vacation-cal-weekdays span {
            text-align: center;
            font-size: 0.75rem;
            color: var(--kvt-muted);
            font-weight: 700;
        }
        .vacation-cal-grid {
            margin-top: 6px;
        }
        .vacation-cal-day {
            appearance: none;
            border: 1px solid var(--kvt-line);
            background: #fff;
            border-radius: 8px;
            min-height: 44px;
            padding: 4px;
            font: inherit;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
        }
        .vacation-cal-day.empty {
            border: 0;
            background: transparent;
            cursor: default;
            min-height: 44px;
        }
        .vacation-cal-day.today {
            border-color: var(--kvt-main-blue);
            box-shadow: inset 0 0 0 1px var(--kvt-main-blue);
            color: var(--kvt-main-blue);
        }
        .vacation-cal-day.off {
            background: #e8ebef;
            color: #7a8794;
            border-color: #d3d9e0;
        }
        .vacation-cal-day.holiday {
            background: #e8f7ee;
            border-color: #9fd6b0;
            flex-direction: column;
            gap: 1px;
            line-height: 1.1;
            font-size: 0.85rem;
        }
        .vacation-cal-day .palm {
            font-size: 1rem;
            line-height: 1;
        }
        .vacation-cal-day:not(.empty):hover {
            border-color: var(--kvt-main-blue);
        }
        @media (max-width: 420px) {
            .time-grid, .flags, .overtime { grid-template-columns: 1fr; }
            .week-day .hours, .week-day .delta { font-size: 0.68rem; }
            .overtime-total { justify-content: flex-start; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="topbar">
        <div class="brand-row">
            <img class="logo" src="logo-website.png" alt="KVT">
            <h1 class="brand-display">Janus</h1>
        </div>
        <div class="toolbar">
            <div class="export-menu">
                <details>
                    <summary class="btn btn-primary">PDF ▾</summary>
                    <div class="menu">
                        <button type="button"
                                class="js-open-pdf-preview-month"
                                data-day="<?= janus_h($selectedDay->format('Y-m-d')) ?>">
                            Deze maand
                        </button>
                        <button type="button" class="js-open-range-modal">Aangepaste periode…</button>
                    </div>
                </details>
            </div>
            <?php if ($canAccessOfficeDays): ?>
                <a class="btn" href="office_days.php">Kantoordagen</a>
            <?php endif; ?>
            <?php if ($canAccessOverview): ?>
                <a class="btn" href="overview.php">Overzicht</a>
            <?php endif; ?>
            <a class="btn" href="settings.php?day=<?= janus_h($selectedDay->format('Y-m-d')) ?>">Instellingen</a>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="flash flash-ok"><?= janus_h($message) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="flash flash-err"><?= janus_h($error) ?></div>
    <?php endif; ?>

    <form method="post" id="dayForm" action="index.php">
        <input type="hidden" name="day" value="<?= janus_h($selectedDay->format('Y-m-d')) ?>">
        <input type="hidden" name="action" id="formAction" value="save">
        <input type="hidden" name="goto_day" id="gotoDay" value="">

        <div class="day-nav">
            <button type="submit" class="btn" onclick="document.getElementById('formAction').value='prevweek'" title="7 dagen terug">↞</button>
            <button type="submit" class="btn" onclick="document.getElementById('formAction').value='prev'" title="Vorige dag">←</button>
            <button type="button" class="day-header" id="dayHeader"
                    data-date-label="<?= janus_h($headerDateLabel) ?>"
                    data-holiday="<?= !empty($dayData['isHoliday']) ? '1' : '0' ?>"
                    data-sick="<?= !empty($dayData['isSickDay']) ? '1' : '0' ?>">
                <?= janus_h($headerText) ?>
            </button>
            <button type="submit" class="btn" <?= $canGoNext ? '' : 'disabled' ?>
                    onclick="document.getElementById('formAction').value='next'" title="Volgende dag">→</button>
            <button type="submit" class="btn" <?= $canGoNextWeek ? '' : 'disabled' ?>
                    onclick="document.getElementById('formAction').value='nextweek'" title="7 dagen vooruit">↠</button>
        </div>

        <div class="panel">
            <div class="time-grid">
                <div>
                    <label for="startTime">Begintijd</label>
                    <input type="time" id="startTime" name="startTime" value="<?= janus_h($startValue) ?>" <?= $timeDisabled ? 'disabled' : '' ?>>
                    <div class="shortcuts">
                        <button type="button" class="btn" id="btnStartNow" <?= $timeDisabled ? 'disabled' : '' ?>>Nu</button>
                    </div>
                </div>
                <div>
                    <label for="endTime">Eindtijd</label>
                    <input type="time" id="endTime" name="endTime" value="<?= janus_h($endValue) ?>" <?= $timeDisabled ? 'disabled' : '' ?>>
                    <div class="shortcuts">
                        <button type="button" class="btn" id="btnEndNow" <?= $timeDisabled ? 'disabled' : '' ?>>Nu</button>
                        <button type="button" class="btn" id="btnAutoEnd" <?= $timeDisabled ? 'disabled' : '' ?>>Auto</button>
                    </div>
                </div>
            </div>

            <div style="margin-top:12px">
                <label for="breakMinutes">Pauze (minuten)</label>
                <input type="number" id="breakMinutes" name="breakMinutes" min="0" max="600"
                       value="<?= (int) $dayData['BreakMinutes'] ?>" <?= $timeDisabled ? 'disabled' : '' ?>>
                <div class="shortcuts">
                    <button type="button" class="btn break-preset" data-minutes="30" <?= $timeDisabled ? 'disabled' : '' ?>>30</button>
                    <button type="button" class="btn break-preset" data-minutes="15" <?= $timeDisabled ? 'disabled' : '' ?>>15</button>
                    <button type="button" class="btn break-preset" data-minutes="0" <?= $timeDisabled ? 'disabled' : '' ?>>0</button>
                </div>
            </div>

            <div class="flags">
                <label class="flag">
                    <input type="checkbox" name="homeWorkDriven" value="1" id="homeWorkDriven"
                        <?= !empty($dayData['HomeWorkDriven']) ? 'checked' : '' ?>
                        <?= $timeDisabled ? 'disabled' : '' ?>>
                    <span>Kantoor</span>
                </label>
                <label class="flag">
                    <input type="checkbox" name="isHoliday" value="1" id="isHoliday"
                        <?= !empty($dayData['isHoliday']) ? 'checked' : '' ?>
                        <?= !empty($dayData['isSickDay']) ? 'disabled' : '' ?>>
                    <span>Vakantiedag</span>
                </label>
                <label class="flag">
                    <input type="checkbox" name="isSickDay" value="1" id="isSickDay"
                        <?= !empty($dayData['isSickDay']) ? 'checked' : '' ?>
                        <?= !empty($dayData['isHoliday']) ? 'disabled' : '' ?>>
                    <span>Ziek</span>
                </label>
                <button type="button" class="btn flag-btn" id="btnFutureVacation">Vakantieplanner</button>
            </div>

            <div class="km-row">
                <label for="kilometers">Extra Kilometers</label>
                <input type="number" id="kilometers" name="kilometers" min="0" step="1"
                       value="<?= janus_h((string) (int) $dayData['Kilometers']) ?>"
                       <?= $timeDisabled ? 'disabled' : '' ?>>
            </div>
        </div>

        <div class="panel">
            <div class="week-strip">
                <?php foreach ($weekStrip as $cell): ?>
                    <button type="submit" class="week-day<?= $cell['selected'] ? ' selected' : '' ?>"
                            onclick="document.getElementById('formAction').value='goto'; document.getElementById('gotoDay').value='<?= janus_h($cell['date']->format('Y-m-d')) ?>';">
                        <span class="name"><?= janus_h($cell['label']) ?></span>
                        <span class="hours"><?= janus_h($cell['hours']) ?></span>
                        <span class="delta <?= janus_h($cell['deltaClass']) ?>"><?= janus_h($cell['delta']) ?></span>
                    </button>
                <?php endforeach; ?>
            </div>

            <div class="overtime">
                <div class="overtime-meta">
                    <div class="muted">Overuren deze maand</div>
                    <div id="overtimeMonth" class="<?= $extra > 0 ? 'plus' : ($extra < 0 ? 'minus' : 'zero') ?>"><?= janus_h(hours_format_extra_label($extra)) ?></div>
                    <div class="muted">vorige maand</div>
                    <div id="overtimePrev" class="<?= $extraPrev > 0 ? 'plus' : ($extraPrev < 0 ? 'minus' : 'zero') ?>"><?= janus_h(hours_format_extra_label($extraPrev)) ?></div>
                </div>
                <div class="overtime-total">
                    <div id="overtimeTotal" class="total <?= $extraTotal > 0 ? 'plus' : ($extraTotal < 0 ? 'minus' : 'zero') ?>">
                        Totaal: <?= janus_h(hours_format_extra_label($extraTotal)) ?>
                    </div>
                </div>
            </div>
        </div>

    </form>
</div>

<div class="range-modal" id="dayPickerModal" aria-hidden="true">
    <div class="range-modal-card">
        <h3 style="margin:0 0 10px;color:var(--kvt-perkins-blue)">Ga naar dag</h3>
        <label for="dayPickerInput">Datum</label>
        <input type="date" id="dayPickerInput" max="<?= janus_h($today->format('Y-m-d')) ?>">
        <div class="range-modal-actions">
            <button type="button" class="btn" id="dayPickerCancel">Annuleren</button>
            <button type="button" class="btn btn-primary" id="dayPickerGo">Open dag</button>
        </div>
    </div>
</div>

<div class="pdf-modal" id="pdfModal" aria-hidden="true">
    <div class="pdf-modal-dialog">
        <div class="pdf-modal-bar">
            <strong>PDF</strong>
            <div style="display:flex;gap:8px;">
                <button type="button" class="btn btn-primary" id="pdfModalPrint">Print</button>
                <button type="button" class="btn" id="pdfModalClose">Sluiten</button>
            </div>
        </div>
        <div class="pdf-modal-body">
            <div id="pdfPreviewContent" class="pdf-preview-content"></div>
        </div>
    </div>
</div>

<div class="range-modal" id="rangeModal" aria-hidden="true">
    <div class="range-modal-card">
        <h3 style="margin:0 0 10px;color:var(--kvt-perkins-blue)">Aangepaste periode</h3>
        <label for="rangeStart">Startdatum</label>
        <input type="date" id="rangeStart">
        <label for="rangeEnd" style="margin-top:8px;">Einddatum</label>
        <input type="date" id="rangeEnd">
        <div class="range-modal-actions">
            <button type="button" class="btn" id="rangeModalCancel">Annuleren</button>
            <button type="button" class="btn btn-primary" id="rangeModalShow">Toon PDF</button>
        </div>
    </div>
</div>

<div class="range-modal" id="vacationModal" aria-hidden="true">
    <div class="range-modal-card vacation-cal-card">
        <h3 style="margin:0 0 10px;color:var(--kvt-perkins-blue)">Vakantieplanner</h3>
        <div class="vacation-cal-nav">
            <button type="button" class="btn" id="vacationPrevMonth" title="Vorige maand">←</button>
            <div class="month-label" id="vacationMonthLabel">—</div>
            <button type="button" class="btn" id="vacationNextMonth" title="Volgende maand">→</button>
        </div>
        <div class="vacation-cal-weekdays">
            <span>Ma</span><span>Di</span><span>Wo</span><span>Do</span><span>Vr</span><span>Za</span><span>Zo</span>
        </div>
        <div class="vacation-cal-grid" id="vacationCalGrid"></div>
        <div class="range-modal-actions">
            <button type="button" class="btn" id="vacationModalClose">Sluiten</button>
        </div>
    </div>
</div>

<script>
(function () {
    var contractSeconds = <?= (int) $contractSeconds ?>;
    var overtimeBaseMonth = <?= (int) $extraBaseWithoutSelected ?>;
    var overtimePrevSeconds = <?= (int) $extraPrev ?>;
    var start = document.getElementById('startTime');
    var end = document.getElementById('endTime');
    var brk = document.getElementById('breakMinutes');
    var holiday = document.getElementById('isHoliday');
    var sick = document.getElementById('isSickDay');
    var header = document.getElementById('dayHeader');
    var overtimeMonthEl = document.getElementById('overtimeMonth');
    var overtimePrevEl = document.getElementById('overtimePrev');
    var overtimeTotalEl = document.getElementById('overtimeTotal');
    var dayPickerModal = document.getElementById('dayPickerModal');
    var dayPickerInput = document.getElementById('dayPickerInput');
    var dayPickerCancel = document.getElementById('dayPickerCancel');
    var dayPickerGo = document.getElementById('dayPickerGo');
    var pdfModal = document.getElementById('pdfModal');
    var pdfPreviewContent = document.getElementById('pdfPreviewContent');
    var pdfModalClose = document.getElementById('pdfModalClose');
    var pdfModalPrint = document.getElementById('pdfModalPrint');
    var rangeModal = document.getElementById('rangeModal');
    var rangeStart = document.getElementById('rangeStart');
    var rangeEnd = document.getElementById('rangeEnd');
    var rangeModalCancel = document.getElementById('rangeModalCancel');
    var rangeModalShow = document.getElementById('rangeModalShow');
    var autosaveStatus = document.getElementById('autosaveStatus');
    var autosaveTimer = 0;
    var autosaveInFlight = false;
    var todayIso = '<?= janus_h($today->format('Y-m-d')) ?>';

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function nowHm() {
        var d = new Date();
        return pad(d.getHours()) + ':' + pad(d.getMinutes());
    }
    function parseHm(v) {
        var p = (v || '00:00').split(':');
        return (parseInt(p[0], 10) || 0) * 3600 + (parseInt(p[1], 10) || 0) * 60;
    }
    function formatHm(sec) {
        if (sec < 0) sec = 0;
        var h = Math.floor(sec / 3600) % 24;
        var m = Math.floor((sec % 3600) / 60);
        return pad(h) + ':' + pad(m);
    }
    function ensureEndAfterStart() {
        if (parseHm(end.value) < parseHm(start.value)) {
            end.value = start.value;
        }
    }
    function formatExtraLabel(seconds) {
        var sign = seconds < 0 ? '-' : '+';
        seconds = Math.abs(seconds);
        var h = Math.floor(seconds / 3600);
        var m = Math.floor((seconds % 3600) / 60);
        return sign + pad(h) + ':' + pad(m);
    }
    function setOvertimeClass(el, seconds, keepTotal) {
        var cls = seconds > 0 ? 'plus' : (seconds < 0 ? 'minus' : 'zero');
        el.className = (keepTotal ? 'total ' : '') + cls;
    }
    function currentWorkedSeconds() {
        if (holiday.checked || sick.checked) {
            return 0;
        }
        var worked = parseHm(end.value) - parseHm(start.value) - ((parseInt(brk.value, 10) || 0) * 60);
        return worked < 0 ? 0 : worked;
    }
    function updateOvertime() {
        if (!overtimeMonthEl || !overtimeTotalEl) return;
        var worked = currentWorkedSeconds();
        var contribution = 0;
        if (!holiday.checked && !sick.checked && (contractSeconds > 0 || worked > 0)) {
            contribution = worked - contractSeconds;
        }
        var monthExtra = overtimeBaseMonth + contribution;
        var totalExtra = monthExtra + overtimePrevSeconds;
        overtimeMonthEl.textContent = formatExtraLabel(monthExtra);
        setOvertimeClass(overtimeMonthEl, monthExtra, false);
        if (overtimePrevEl) {
            overtimePrevEl.textContent = formatExtraLabel(overtimePrevSeconds);
            setOvertimeClass(overtimePrevEl, overtimePrevSeconds, false);
        }
        overtimeTotalEl.textContent = 'Totaal: ' + formatExtraLabel(totalExtra);
        setOvertimeClass(overtimeTotalEl, totalExtra, true);
    }
    function updateDayHeader() {
        var dateLabel = header.getAttribute('data-date-label') || '';
        if (holiday.checked) {
            header.textContent = dateLabel + ' - Vakantiedag';
            updateOvertime();
            return;
        }
        if (sick.checked) {
            header.textContent = dateLabel + ' - Ziek';
            updateOvertime();
            return;
        }
        var worked = currentWorkedSeconds();
        var h = Math.floor(worked / 3600);
        var m = Math.floor((worked % 3600) / 60);
        var minuteLabel = m === 1 ? 'minuut' : 'minuten';
        header.textContent = dateLabel + ' - ' + h + ' uur, ' + m + ' ' + minuteLabel + ' gewerkt';
        updateOvertime();
    }
    function applySpecialDayState() {
        var disabled = holiday.checked || sick.checked;
        Array.prototype.forEach.call(document.querySelectorAll('#startTime, #endTime, #breakMinutes, #kilometers, #homeWorkDriven, #btnStartNow, #btnEndNow, #btnAutoEnd, .break-preset'), function (el) {
            el.disabled = disabled;
        });
    }
    function setAutosaveStatus(text, color) {
        if (!autosaveStatus) return;
        autosaveStatus.textContent = text ? ' · ' + text : '';
        autosaveStatus.style.color = color || '';
    }
    function buildAutosaveFormData() {
        var form = new FormData();
        form.append('day', document.querySelector('#dayForm input[name="day"]').value);
        form.append('startTime', start.value || '');
        form.append('endTime', end.value || '');
        form.append('breakMinutes', brk.value || '0');
        form.append('kilometers', document.getElementById('kilometers').value || '0');
        if (document.getElementById('homeWorkDriven').checked) form.append('homeWorkDriven', '1');
        if (holiday.checked) form.append('isHoliday', '1');
        if (sick.checked) form.append('isSickDay', '1');
        return form;
    }
    function autosaveNow() {
        if (autosaveInFlight) return;
        autosaveInFlight = true;
        setAutosaveStatus('Opslaan...', 'var(--kvt-muted)');
        fetch('hours_save.php', {
            method: 'POST',
            body: buildAutosaveFormData(),
            cache: 'no-store'
        })
            .then(function (resp) { return resp.json(); })
            .then(function (payload) {
                autosaveInFlight = false;
                if (!payload || !payload.ok) {
                    setAutosaveStatus('Opslaan mislukt', '#b42318');
                    return;
                }
                setAutosaveStatus('Opgeslagen', '#146c2e');
            })
            .catch(function () {
                autosaveInFlight = false;
                setAutosaveStatus('Opslaan mislukt', '#b42318');
            });
    }
    function scheduleAutosave() {
        clearTimeout(autosaveTimer);
        setAutosaveStatus('Wijzigingen...', 'var(--kvt-muted)');
        autosaveTimer = setTimeout(autosaveNow, 250);
    }

    document.getElementById('btnStartNow').addEventListener('click', function () {
        start.value = nowHm();
        ensureEndAfterStart();
        updateDayHeader();
        scheduleAutosave();
    });
    document.getElementById('btnEndNow').addEventListener('click', function () {
        end.value = nowHm();
        ensureEndAfterStart();
        updateDayHeader();
        scheduleAutosave();
    });
    document.getElementById('btnAutoEnd').addEventListener('click', function () {
        var breakMin = parseInt(brk.value, 10) || 0;
        end.value = formatHm(parseHm(start.value) + contractSeconds + (breakMin * 60));
        updateDayHeader();
        scheduleAutosave();
    });
    Array.prototype.forEach.call(document.querySelectorAll('.break-preset'), function (btn) {
        btn.addEventListener('click', function () {
            brk.value = btn.getAttribute('data-minutes');
            updateDayHeader();
            scheduleAutosave();
        });
    });

    start.addEventListener('input', function () {
        updateDayHeader();
        scheduleAutosave();
    });
    start.addEventListener('blur', function () {
        ensureEndAfterStart();
        updateDayHeader();
        scheduleAutosave();
    });
    end.addEventListener('input', function () {
        updateDayHeader();
        scheduleAutosave();
    });
    end.addEventListener('blur', function () {
        ensureEndAfterStart();
        updateDayHeader();
        scheduleAutosave();
    });
    brk.addEventListener('input', function () { updateDayHeader(); scheduleAutosave(); });
    brk.addEventListener('change', function () { updateDayHeader(); scheduleAutosave(); });

    holiday.addEventListener('change', function () {
        if (holiday.checked) sick.checked = false;
        applySpecialDayState();
        updateDayHeader();
        scheduleAutosave();
    });
    sick.addEventListener('change', function () {
        if (sick.checked) holiday.checked = false;
        applySpecialDayState();
        updateDayHeader();
        scheduleAutosave();
    });
    document.getElementById('homeWorkDriven').addEventListener('change', scheduleAutosave);
    document.getElementById('kilometers').addEventListener('input', scheduleAutosave);
    document.getElementById('kilometers').addEventListener('change', scheduleAutosave);

    // Re-enable disabled fields on submit so values are posted
    document.getElementById('dayForm').addEventListener('submit', function () {
        Array.prototype.forEach.call(document.querySelectorAll('#dayForm [disabled]'), function (el) {
            el.disabled = false;
        });
    });

    function closePdfModal() {
        pdfModal.classList.remove('open');
        pdfModal.setAttribute('aria-hidden', 'true');
        pdfPreviewContent.innerHTML = '';
    }

    function closeRangeModal() {
        rangeModal.classList.remove('open');
        rangeModal.setAttribute('aria-hidden', 'true');
    }
    function closeDayPickerModal() {
        dayPickerModal.classList.remove('open');
        dayPickerModal.setAttribute('aria-hidden', 'true');
    }

    function openRangeModal() {
        var now = new Date();
        var end = now.toISOString().slice(0, 10);
        var startDate = new Date(now.getTime() - (31 * 86400000));
        var start = startDate.toISOString().slice(0, 10);
        rangeStart.value = rangeStart.value || start;
        rangeEnd.value = rangeEnd.value || end;
        rangeModal.classList.add('open');
        rangeModal.setAttribute('aria-hidden', 'false');
    }
    function openDayPickerModal() {
        var selectedDay = document.querySelector('#dayForm input[name="day"]').value || todayIso;
        dayPickerInput.value = selectedDay > todayIso ? todayIso : selectedDay;
        dayPickerModal.classList.add('open');
        dayPickerModal.setAttribute('aria-hidden', 'false');
    }
    function goToPickedDay() {
        if (!dayPickerInput.value) return;
        if (dayPickerInput.value > todayIso) {
            dayPickerInput.value = todayIso;
        }
        document.getElementById('formAction').value = 'goto';
        document.getElementById('gotoDay').value = dayPickerInput.value;
        closeDayPickerModal();
        document.getElementById('dayForm').requestSubmit();
    }

    function extractPreviewPayload(html) {
        return html;
    }

    function loadPreview(url) {
        pdfPreviewContent.innerHTML = '<div style="padding:20px;background:#fff">Laden…</div>';
        fetch(url, { cache: 'no-store' })
            .then(function (resp) { return resp.text(); })
            .then(function (html) {
                pdfPreviewContent.innerHTML = extractPreviewPayload(html);
                pdfModal.classList.add('open');
                pdfModal.setAttribute('aria-hidden', 'false');
            })
            .catch(function () {
                pdfPreviewContent.innerHTML = '<div style="padding:20px;background:#fff;color:#b42318">PDF laden mislukt.</div>';
                pdfModal.classList.add('open');
                pdfModal.setAttribute('aria-hidden', 'false');
            });
    }

    function openMonthPreview() {
        var day = document.querySelector('.js-open-pdf-preview-month').getAttribute('data-day') || '';
        var url = 'export_preview.php?fragment=1&mode=month&day=' + encodeURIComponent(day) + '&_ts=' + Date.now();
        loadPreview(url);
        var details = document.querySelector('.export-menu details');
        if (details) details.removeAttribute('open');
    }

    document.querySelector('.js-open-pdf-preview-month').addEventListener('click', openMonthPreview);
    header.addEventListener('click', openDayPickerModal);
    document.querySelector('.js-open-range-modal').addEventListener('click', function () {
        openRangeModal();
        var details = document.querySelector('.export-menu details');
        if (details) details.removeAttribute('open');
    });

    dayPickerCancel.addEventListener('click', closeDayPickerModal);
    dayPickerGo.addEventListener('click', goToPickedDay);
    dayPickerInput.addEventListener('change', function () {
        if (dayPickerInput.value > todayIso) {
            dayPickerInput.value = todayIso;
        }
    });
    dayPickerInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            goToPickedDay();
        }
    });
    rangeModalCancel.addEventListener('click', closeRangeModal);
    rangeModalShow.addEventListener('click', function () {
        if (!rangeStart.value || !rangeEnd.value) return;
        if (rangeEnd.value < rangeStart.value) {
            rangeEnd.value = rangeStart.value;
        }
        var day = document.querySelector('.js-open-pdf-preview-month').getAttribute('data-day') || '';
        var url = 'export_preview.php?fragment=1&mode=custom&day=' + encodeURIComponent(day)
            + '&start=' + encodeURIComponent(rangeStart.value)
            + '&end=' + encodeURIComponent(rangeEnd.value)
            + '&_ts=' + Date.now();
        closeRangeModal();
        loadPreview(url);
    });

    pdfModalClose.addEventListener('click', closePdfModal);
    pdfModalPrint.addEventListener('click', function () {
        if (!pdfPreviewContent.innerHTML.trim()) return;
        var printWin = window.open('', '_blank');
        if (!printWin) return;
        printWin.document.open();
        printWin.document.write('<!doctype html><html><head><meta charset="utf-8"><title>Janus PDF</title></head><body>'
            + pdfPreviewContent.innerHTML + '</body></html>');
        printWin.document.close();
        printWin.focus();
        setTimeout(function () { printWin.print(); }, 150);
    });
    pdfModal.addEventListener('click', function (e) {
        if (e.target === pdfModal) {
            closePdfModal();
        }
    });
    rangeModal.addEventListener('click', function (e) {
        if (e.target === rangeModal) {
            closeRangeModal();
        }
    });
    var vacationModal = document.getElementById('vacationModal');
    var vacationCalGrid = document.getElementById('vacationCalGrid');
    var vacationMonthLabel = document.getElementById('vacationMonthLabel');
    var vacationPrevMonth = document.getElementById('vacationPrevMonth');
    var vacationNextMonth = document.getElementById('vacationNextMonth');
    var vacationModalClose = document.getElementById('vacationModalClose');
    var vacationToggleBusy = false;
    var vacationView = (function () {
        var parts = todayIso.split('-');
        return {
            year: parseInt(parts[0], 10) || new Date().getFullYear(),
            month: parseInt(parts[1], 10) || (new Date().getMonth() + 1)
        };
    })();

    var vacationDirty = false;

    function closeVacationModal() {
        vacationModal.classList.remove('open');
        vacationModal.setAttribute('aria-hidden', 'true');
        if (vacationDirty) {
            var selectedDay = document.querySelector('#dayForm input[name="day"]').value || todayIso;
            window.location.href = 'index.php?day=' + encodeURIComponent(selectedDay);
        }
    }

    function openVacationModal() {
        vacationDirty = false;
        vacationModal.classList.add('open');
        vacationModal.setAttribute('aria-hidden', 'false');
        loadVacationMonth();
    }

    function shiftVacationMonth(delta) {
        vacationView.month += delta;
        if (vacationView.month < 1) {
            vacationView.month = 12;
            vacationView.year -= 1;
        } else if (vacationView.month > 12) {
            vacationView.month = 1;
            vacationView.year += 1;
        }
        loadVacationMonth();
    }

    function renderVacationMonth(payload) {
        vacationMonthLabel.textContent = payload.label || (payload.month + '-' + payload.year);
        var html = '';
        var start = parseInt(payload.startWeekday, 10) || 0;
        for (var i = 0; i < start; i++) {
            html += '<div class="vacation-cal-day empty"></div>';
        }
        (payload.days || []).forEach(function (day) {
            var classes = ['vacation-cal-day'];
            if (day.today) classes.push('today');
            if (day.off) classes.push('off');
            if (day.holiday) classes.push('holiday');
            var title = day.off ? ' title="Je werkt op deze dag niet"' : '';
            var label = day.holiday
                ? '<span class="palm">🌴</span><span>' + String(day.day) + '</span>'
                : String(day.day);
            html += '<button type="button" class="' + classes.join(' ') + '"'
                + ' data-iso="' + day.iso + '"'
                + ' data-holiday="' + (day.holiday ? '1' : '0') + '"'
                + title + '>' + label + '</button>';
        });
        vacationCalGrid.innerHTML = html;
        Array.prototype.forEach.call(vacationCalGrid.querySelectorAll('.vacation-cal-day:not(.empty)'), function (btn) {
            btn.addEventListener('click', function () {
                toggleVacationDay(btn);
            });
        });
    }

    function loadVacationMonth() {
        vacationCalGrid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:16px;color:var(--kvt-muted)">Laden...</div>';
        fetch('hours_vacation.php?year=' + encodeURIComponent(vacationView.year)
            + '&month=' + encodeURIComponent(vacationView.month)
            + '&_ts=' + Date.now(), { cache: 'no-store' })
            .then(function (resp) { return resp.json(); })
            .then(function (payload) {
                if (!payload || !payload.ok) throw new Error('invalid');
                vacationView.year = payload.year;
                vacationView.month = payload.month;
                renderVacationMonth(payload);
            })
            .catch(function () {
                vacationCalGrid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:16px;color:#b42318">Laden mislukt.</div>';
            });
    }

    function toggleVacationDay(btn) {
        if (vacationToggleBusy) return;
        var iso = btn.getAttribute('data-iso') || '';
        var currentlyHoliday = btn.getAttribute('data-holiday') === '1';
        var enable = !currentlyHoliday;
        vacationToggleBusy = true;
        var form = new FormData();
        form.append('day', iso);
        if (enable) form.append('holiday', '1');
        fetch('hours_vacation.php', { method: 'POST', body: form, cache: 'no-store' })
            .then(function (resp) { return resp.json(); })
            .then(function (payload) {
                vacationToggleBusy = false;
                if (!payload || !payload.ok) throw new Error('invalid');
                vacationDirty = true;
                var selectedDay = document.querySelector('#dayForm input[name="day"]').value || '';
                if (selectedDay === iso) {
                    window.location.href = 'index.php?day=' + encodeURIComponent(iso);
                    return;
                }
                loadVacationMonth();
            })
            .catch(function () {
                vacationToggleBusy = false;
                setAutosaveStatus('Opslaan mislukt', '#b42318');
            });
    }

    document.getElementById('btnFutureVacation').addEventListener('click', openVacationModal);
    vacationPrevMonth.addEventListener('click', function () { shiftVacationMonth(-1); });
    vacationNextMonth.addEventListener('click', function () { shiftVacationMonth(1); });
    vacationModalClose.addEventListener('click', closeVacationModal);
    vacationModal.addEventListener('click', function (e) {
        if (e.target === vacationModal) {
            closeVacationModal();
        }
    });
    dayPickerModal.addEventListener('click', function (e) {
        if (e.target === dayPickerModal) {
            closeDayPickerModal();
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && pdfModal.classList.contains('open')) {
            closePdfModal();
        }
        if (e.key === 'Escape' && rangeModal.classList.contains('open')) {
            closeRangeModal();
        }
        if (e.key === 'Escape' && dayPickerModal.classList.contains('open')) {
            closeDayPickerModal();
        }
        if (e.key === 'Escape' && vacationModal.classList.contains('open')) {
            closeVacationModal();
        }
    });
    applySpecialDayState();
})();
</script>
</body>
</html>
