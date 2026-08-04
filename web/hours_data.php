<?php

/**
 * Janus hours data — mirrors WinForms SaveData JSON shape for import compatibility.
 */

const HOURS_CACHE_DIR = __DIR__ . '/cache/hours';

/**
 * @return array{
 *   UserName: string,
 *   UserEmail: string,
 *   MondayHours: string,
 *   TuesdayHours: string,
 *   WednesdayHours: string,
 *   ThursdayHours: string,
 *   FridayHours: string,
 *   SaturdayHours: string,
 *   SundayHours: string,
 *   KilometerHomeWork: float|int,
 *   MonthExtraTicks: array<string, int|float|string>,
 *   SavedDays: array<string, array<string, mixed>>
 * }
 */
function hours_default_save_data(string $userName = '', string $userEmail = ''): array
{
    return [
        'UserName' => $userName,
        'UserEmail' => $userEmail,
        'MondayHours' => '08:00:00',
        'TuesdayHours' => '08:00:00',
        'WednesdayHours' => '08:00:00',
        'ThursdayHours' => '08:00:00',
        'FridayHours' => '08:00:00',
        'SaturdayHours' => '00:00:00',
        'SundayHours' => '00:00:00',
        'KilometerHomeWork' => 0.0,
        // Mon..Sun — current defaults (from today onward; history covers the past)
        'DefaultOfficeDays' => [true, true, true, true, true, false, false],
        'MonthExtraTicks' => [],
        'SavedDays' => [],
    ];
}

/**
 * @return array{
 *   StartTime: string,
 *   EndTime: string,
 *   BreakMinutes: int,
 *   Kilometers: float|int,
 *   HomeWorkDriven: bool,
 *   isHoliday: bool,
 *   isSickDay: bool,
 *   WorkedTime: string,
 *   WorkedString: string
 * }
 */
function hours_default_day_data(): array
{
    $day = [
        'StartTime' => '09:00:00',
        'EndTime' => '17:30:00',
        'BreakMinutes' => 30,
        'Kilometers' => 0.0,
        'HomeWorkDriven' => false,
        'isHoliday' => false,
        'isSickDay' => false,
    ];

    return hours_enrich_day_data($day);
}

/**
 * @param array<string, mixed> $day
 * @return array<string, mixed>
 */
function hours_enrich_day_data(array $day): array
{
    $start = hours_parse_timespan((string) ($day['StartTime'] ?? '09:00:00'));
    $end = hours_parse_timespan((string) ($day['EndTime'] ?? '17:30:00'));
    $break = (int) ($day['BreakMinutes'] ?? 0);
    $workedSeconds = ($end - $start) - ($break * 60);
    $worked = hours_format_timespan_from_seconds($workedSeconds);

    $day['StartTime'] = hours_format_timespan_from_seconds($start);
    $day['EndTime'] = hours_format_timespan_from_seconds($end);
    $day['BreakMinutes'] = $break;
    $day['Kilometers'] = (float) ($day['Kilometers'] ?? 0);
    $day['HomeWorkDriven'] = (bool) ($day['HomeWorkDriven'] ?? false);
    $day['isHoliday'] = (bool) ($day['isHoliday'] ?? false);
    $day['isSickDay'] = (bool) ($day['isSickDay'] ?? false);
    $day['WorkedTime'] = $worked;
    $day['WorkedString'] = hours_worked_string_from_seconds($workedSeconds);

    return $day;
}

function hours_day_key(DateTimeInterface $date): string
{
    return $date->format('d-m-Y');
}

function hours_month_key(int $month, int $year): string
{
    return sprintf('%02d-%d', $month, $year);
}

function hours_parse_day_key(string $key): ?DateTimeImmutable
{
    $key = trim($key);
    if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $key, $m) !== 1) {
        return null;
    }
    if (!checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
        return null;
    }

    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]));
}

/**
 * Parse HH:mm[:ss] to total seconds (can be negative if malformed end&lt;start later).
 */
function hours_parse_timespan(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }

    $negative = false;
    if ($value[0] === '-') {
        $negative = true;
        $value = substr($value, 1);
    }

    $parts = array_map('intval', explode(':', $value));
    $h = $parts[0] ?? 0;
    $m = $parts[1] ?? 0;
    $s = $parts[2] ?? 0;
    $total = ($h * 3600) + ($m * 60) + $s;

    return $negative ? -$total : $total;
}

function hours_format_timespan_from_seconds(int $seconds): string
{
    $negative = $seconds < 0;
    $seconds = abs($seconds);
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    $formatted = sprintf('%02d:%02d:%02d', $h, $m, $s);

    return $negative ? '-' . $formatted : $formatted;
}

function hours_format_hhmm_from_seconds(int $seconds): string
{
    $negative = $seconds < 0;
    $seconds = abs($seconds);
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);

    return ($negative ? '-' : '') . sprintf('%02d:%02d', $h, $m);
}

function hours_worked_string_from_seconds(int $seconds): string
{
    $negative = $seconds < 0;
    $seconds = abs($seconds);
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $minuteLabel = $m === 1 ? 'minuut' : 'minuten';
    $text = sprintf('%d uur, %d %s', $h, $m, $minuteLabel);

    return $negative ? '-' . $text : $text;
}

/**
 * Weekday index: Monday=0 … Sunday=6 (same as WinForms).
 */
function hours_weekday_index(DateTimeInterface $date): int
{
    return ((int) $date->format('w') + 6) % 7;
}

/**
 * @param array<string, mixed> $data
 */
function hours_get_work_hours_seconds(array $data, int $dayNumber): int
{
    $keys = [
        0 => 'MondayHours',
        1 => 'TuesdayHours',
        2 => 'WednesdayHours',
        3 => 'ThursdayHours',
        4 => 'FridayHours',
        5 => 'SaturdayHours',
        6 => 'SundayHours',
    ];
    $key = $keys[$dayNumber] ?? null;
    if ($key === null) {
        return 0;
    }

    return hours_parse_timespan((string) ($data[$key] ?? '00:00:00'));
}

/**
 * Away/vacation/sick status for a single day (for Asclepius sync).
 *
 * @param array<string, mixed> $data
 * @return array{known: true, holiday: bool, sick: bool, contractOff: bool, locked: bool, reason: string|null}
 */
function hours_day_away_status(array $data, DateTimeInterface $date): array
{
    $weekday = hours_weekday_index($date);
    $contractOff = hours_get_work_hours_seconds($data, $weekday) <= 0;
    $holiday = false;
    $sick = false;
    if (hours_day_exists($data, $date)) {
        $key = hours_day_key($date);
        $day = $data['SavedDays'][$key] ?? null;
        if (is_array($day)) {
            $holiday = !empty($day['isHoliday']);
            $sick = !empty($day['isSickDay']);
        }
    }

    $locked = $holiday || $sick || $contractOff;
    $reason = null;
    if ($holiday) {
        $reason = 'janus_holiday';
    } elseif ($sick) {
        $reason = 'janus_sick';
    } elseif ($contractOff) {
        $reason = 'contract_off';
    }

    return [
        'known' => true,
        'holiday' => $holiday,
        'sick' => $sick,
        'contractOff' => $contractOff,
        'locked' => $locked,
        'reason' => $reason,
    ];
}

/**
 * Last consecutive vacation day starting at $date (inclusive), or null if not on holiday.
 */
function hours_holiday_end_date(array $data, DateTimeInterface $date): ?DateTimeImmutable
{
    if (!hours_day_exists($data, $date)) {
        return null;
    }
    $key = hours_day_key($date);
    $day = $data['SavedDays'][$key] ?? null;
    if (!is_array($day) || empty($day['isHoliday'])) {
        return null;
    }

    $end = $date instanceof DateTimeImmutable ? $date : DateTimeImmutable::createFromInterface($date);
    $cursor = $end;
    for ($i = 0; $i < 365; $i++) {
        $next = $cursor->modify('+1 day');
        if (!hours_day_exists($data, $next)) {
            break;
        }
        $nextKey = hours_day_key($next);
        $nextDay = $data['SavedDays'][$nextKey] ?? null;
        if (!is_array($nextDay) || empty($nextDay['isHoliday'])) {
            break;
        }
        $end = $next;
        $cursor = $next;
    }

    return $end;
}

/**
 * Live presence status for Asclepius sidebar (full tracker only).
 *
 * @param array<string, mixed> $data
 * @return array{
 *   visible: bool,
 *   status: string|null,
 *   lightMode: bool,
 *   name: string,
 *   office: bool,
 *   startTime: string|null,
 *   endTime: string|null,
 *   holidayUntil: string|null,
 *   contractOff: bool,
 *   knownDay: bool
 * }
 */
function hours_day_presence_status(array $data, DateTimeInterface $date, ?DateTimeInterface $now = null): array
{
    $email = strtolower(trim((string) ($data['UserEmail'] ?? '')));
    $name = trim((string) ($data['UserName'] ?? ''));
    $lightMode = true;
    if ($email !== '' && function_exists('loadUserPrefs')) {
        $prefs = loadUserPrefs($email);
        if (array_key_exists('lightMode', $prefs)) {
            $lightMode = (bool) $prefs['lightMode'];
        } else {
            $lightMode = true;
            foreach (($data['SavedDays'] ?? []) as $savedDay) {
                if (is_array($savedDay) && hours_is_full_tracker_day($savedDay)) {
                    $lightMode = false;
                    break;
                }
            }
        }
    } elseif (function_exists('hours_is_full_tracker_day')) {
        foreach (($data['SavedDays'] ?? []) as $savedDay) {
            if (is_array($savedDay) && hours_is_full_tracker_day($savedDay)) {
                $lightMode = false;
                break;
            }
        }
    }
    $now = $now ?? new DateTimeImmutable('now');
    $weekday = hours_weekday_index($date);
    $contractOff = hours_get_work_hours_seconds($data, $weekday) <= 0;

    $base = [
        'visible' => false,
        'status' => null,
        'lightMode' => $lightMode,
        'name' => $name,
        'office' => false,
        'startTime' => null,
        'endTime' => null,
        'holidayUntil' => null,
        'contractOff' => $contractOff,
        'knownDay' => false,
    ];

    if ($lightMode) {
        return $base;
    }

    $base['visible'] = true;
    $knownDay = hours_day_exists($data, $date);
    $base['knownDay'] = $knownDay;

    $holiday = false;
    $sick = false;
    $office = hours_default_office_for_date($data, $date);
    $startSeconds = null;
    $endSeconds = null;

    if ($knownDay) {
        $key = hours_day_key($date);
        $day = $data['SavedDays'][$key] ?? null;
        if (is_array($day)) {
            $holiday = !empty($day['isHoliday']);
            $sick = !empty($day['isSickDay']);
            $office = !empty($day['HomeWorkDriven']);
            $startSeconds = hours_parse_timespan((string) ($day['StartTime'] ?? '00:00:00'));
            $endSeconds = hours_parse_timespan((string) ($day['EndTime'] ?? '00:00:00'));
            $base['startTime'] = substr((string) ($day['StartTime'] ?? '00:00:00'), 0, 5);
            $base['endTime'] = substr((string) ($day['EndTime'] ?? '00:00:00'), 0, 5);
        }
    }

    $base['office'] = $office;

    if ($holiday) {
        $until = hours_holiday_end_date($data, $date);
        $base['status'] = 'holiday';
        $base['holidayUntil'] = $until instanceof DateTimeImmutable ? $until->format('Y-m-d') : $date->format('Y-m-d');

        return $base;
    }

    if ($sick) {
        $base['status'] = 'sick';

        return $base;
    }

    if (!$knownDay) {
        $base['status'] = 'unknown';

        return $base;
    }

    $nowSeconds = ((int) $now->format('G')) * 3600
        + ((int) $now->format('i')) * 60
        + (int) $now->format('s');
    $withinTimes = $startSeconds !== null
        && $endSeconds !== null
        && $nowSeconds >= $startSeconds
        && $nowSeconds <= $endSeconds;

    if ($contractOff && !$withinTimes) {
        $base['status'] = 'off_today';

        return $base;
    }

    if ($withinTimes) {
        $base['status'] = $office ? 'present_office' : 'present_home';

        return $base;
    }

    $base['status'] = 'absent';

    return $base;
}

/**
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function hours_get_day(array &$data, DateTimeInterface $date): array
{
    $key = hours_day_key($date);
    if (!isset($data['SavedDays'][$key]) || !is_array($data['SavedDays'][$key])) {
        $data['SavedDays'][$key] = hours_default_day_data();
    } else {
        $data['SavedDays'][$key] = hours_enrich_day_data($data['SavedDays'][$key]);
    }

    return $data['SavedDays'][$key];
}

function hours_day_exists(array $data, DateTimeInterface $date): bool
{
    $key = hours_day_key($date);

    return isset($data['SavedDays'][$key]) && is_array($data['SavedDays'][$key]);
}

/**
 * @param array<string, mixed> $data
 */
function hours_delete_day(array &$data, DateTimeInterface $date): void
{
    $key = hours_day_key($date);
    if (isset($data['SavedDays'][$key])) {
        unset($data['SavedDays'][$key]);
    }
}

/**
 * @return array<string, mixed>
 */
/**
 * Normalize DefaultOfficeDays to 7 booleans (Mon..Sun).
 *
 * @param mixed $value
 * @return list<bool>
 */
function hours_normalize_default_office_days(mixed $value): array
{
    $defaults = [true, true, true, true, true, false, false];
    if (!is_array($value)) {
        return $defaults;
    }

    $out = [];
    for ($i = 0; $i < 7; $i++) {
        $out[] = !empty($value[$i]);
    }

    return $out;
}

/**
 * @param mixed $history
 * @return list<array{From: string, Days: list<bool>}>
 */
function hours_normalize_default_office_history(mixed $history, ?array $fallbackDays = null): array
{
    $fallbackDays = hours_normalize_default_office_days($fallbackDays);
    if (!is_array($history) || $history === []) {
        return [['From' => '1970-01-01', 'Days' => $fallbackDays]];
    }

    $out = [];
    foreach ($history as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $from = trim((string) ($entry['From'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1) {
            continue;
        }
        $out[] = [
            'From' => $from,
            'Days' => hours_normalize_default_office_days($entry['Days'] ?? null),
        ];
    }

    if ($out === []) {
        return [['From' => '1970-01-01', 'Days' => $fallbackDays]];
    }

    usort($out, static function (array $a, array $b): int {
        return strcmp($a['From'], $b['From']);
    });

    return $out;
}

/**
 * Ensure history exists; seed from current DefaultOfficeDays when missing.
 *
 * @param array<string, mixed> $data
 */
function hours_ensure_default_office_history(array &$data): void
{
    $current = hours_normalize_default_office_days($data['DefaultOfficeDays'] ?? null);
    $data['DefaultOfficeDays'] = $current;

    if (!isset($data['DefaultOfficeDaysHistory']) || !is_array($data['DefaultOfficeDaysHistory']) || $data['DefaultOfficeDaysHistory'] === []) {
        $data['DefaultOfficeDaysHistory'] = [
            ['From' => '1970-01-01', 'Days' => $current],
        ];

        return;
    }

    $data['DefaultOfficeDaysHistory'] = hours_normalize_default_office_history(
        $data['DefaultOfficeDaysHistory'],
        $current
    );

    // Keep current mirror in sync with latest history entry.
    $history = $data['DefaultOfficeDaysHistory'];
    $latest = $history[count($history) - 1];
    $data['DefaultOfficeDays'] = $latest['Days'];
}

/**
 * @param array<string, mixed> $data
 * @return list<bool>
 */
function hours_get_default_office_days(array $data): array
{
    return hours_normalize_default_office_days($data['DefaultOfficeDays'] ?? null);
}

/**
 * Defaults that were in force on a specific calendar date.
 *
 * @param array<string, mixed> $data
 * @return list<bool>
 */
function hours_get_default_office_days_on(array $data, DateTimeInterface $date): array
{
    $iso = DateTimeImmutable::createFromInterface($date)->format('Y-m-d');
    $history = hours_normalize_default_office_history(
        $data['DefaultOfficeDaysHistory'] ?? null,
        $data['DefaultOfficeDays'] ?? null
    );

    $chosen = $history[0]['Days'];
    foreach ($history as $entry) {
        if ($entry['From'] <= $iso) {
            $chosen = $entry['Days'];
        } else {
            break;
        }
    }

    return $chosen;
}

/**
 * Apply new weekday defaults starting today. Past days keep earlier history entries.
 *
 * @param array<string, mixed> $data
 * @param list<bool> $days
 */
function hours_set_default_office_days_from(array &$data, array $days, DateTimeInterface $from): void
{
    hours_ensure_default_office_history($data);
    $days = hours_normalize_default_office_days($days);
    $fromIso = DateTimeImmutable::createFromInterface($from)->format('Y-m-d');
    $history = $data['DefaultOfficeDaysHistory'];
    $current = hours_get_default_office_days($data);

    if ($current === $days) {
        $data['DefaultOfficeDays'] = $days;
        return;
    }

    $replaced = false;
    for ($i = count($history) - 1; $i >= 0; $i--) {
        if ($history[$i]['From'] === $fromIso) {
            $history[$i]['Days'] = $days;
            $replaced = true;
            break;
        }
    }
    if (!$replaced) {
        $history[] = ['From' => $fromIso, 'Days' => $days];
    }

    $data['DefaultOfficeDaysHistory'] = hours_normalize_default_office_history($history, $days);
    $data['DefaultOfficeDays'] = $days;
}

/**
 * @param array<string, mixed> $data
 */
function hours_default_office_for_weekday(array $data, int $weekdayIndex): bool
{
    $days = hours_get_default_office_days($data);
    $weekdayIndex = max(0, min(6, $weekdayIndex));

    return !empty($days[$weekdayIndex]);
}

/**
 * @param array<string, mixed> $data
 */
function hours_default_office_for_date(array $data, DateTimeInterface $date): bool
{
    $days = hours_get_default_office_days_on($data, $date);
    $weekdayIndex = hours_weekday_index($date);

    return !empty($days[$weekdayIndex]);
}

/**
 * Effective office day: explicit SavedDay wins, otherwise weekday default for that date.
 *
 * @param array<string, mixed> $data
 */
function hours_is_effective_office_day(array $data, DateTimeInterface $date): bool
{
    if (hours_day_exists($data, $date)) {
        $row = hours_get_day($data, $date);
        if (!empty($row['isHoliday']) || !empty($row['isSickDay'])) {
            return false;
        }

        return !empty($row['HomeWorkDriven']);
    }

    return hours_default_office_for_date($data, $date);
}

/**
 * Parse posted weekday checkboxes into DefaultOfficeDays.
 *
 * @param array<string, mixed> $post
 * @return list<bool>
 */
function hours_default_office_days_from_post(array $post): array
{
    $keys = [
        'DefaultOfficeMon',
        'DefaultOfficeTue',
        'DefaultOfficeWed',
        'DefaultOfficeThu',
        'DefaultOfficeFri',
        'DefaultOfficeSat',
        'DefaultOfficeSun',
    ];
    $out = [];
    foreach ($keys as $i => $key) {
        $out[] = !empty($post[$key]) || !empty($post['DefaultOfficeDays'][$i]);
    }

    return $out;
}

function hours_vacation_day_data(): array
{
    return hours_enrich_day_data([
        'StartTime' => '00:00:00',
        'EndTime' => '00:00:00',
        'BreakMinutes' => 0,
        'Kilometers' => 0.0,
        'HomeWorkDriven' => false,
        'isHoliday' => true,
        'isSickDay' => false,
    ]);
}

/**
 * Minimal day used by Light-mode office toggles.
 *
 * @return array<string, mixed>
 */
function hours_office_light_day_data(bool $office): array
{
    return hours_enrich_day_data([
        'StartTime' => '00:00:00',
        'EndTime' => '00:00:00',
        'BreakMinutes' => 0,
        'Kilometers' => 0.0,
        'HomeWorkDriven' => $office,
        'isHoliday' => false,
        'isSickDay' => false,
    ]);
}

/**
 * Day created only by Light mode (no times / leave flags).
 *
 * @param array<string, mixed> $day
 */
function hours_is_office_light_stub(array $day): bool
{
    if (!empty($day['isHoliday']) || !empty($day['isSickDay'])) {
        return false;
    }
    if (hours_parse_timespan((string) ($day['StartTime'] ?? '00:00:00')) !== 0) {
        return false;
    }
    if (hours_parse_timespan((string) ($day['EndTime'] ?? '00:00:00')) !== 0) {
        return false;
    }
    if ((int) ($day['BreakMinutes'] ?? 0) !== 0) {
        return false;
    }
    if ((float) ($day['Kilometers'] ?? 0) != 0.0) {
        return false;
    }

    return true;
}

/**
 * Day that indicates the user already used the full urentracker.
 *
 * @param array<string, mixed> $day
 */
function hours_is_full_tracker_day(array $day): bool
{
    if (!empty($day['isHoliday']) || !empty($day['isSickDay'])) {
        return true;
    }
    if (!hours_is_office_light_stub($day)) {
        return true;
    }

    return false;
}

/**
 * First visit for a day: break=0, end = start + contract hours (WinForms LoadDay).
 *
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function hours_ensure_day_initialized(array &$data, DateTimeInterface $date): array
{
    $isNew = !hours_day_exists($data, $date);
    $day = hours_get_day($data, $date);

    if ($isNew) {
        $dayNumber = hours_weekday_index($date);
        $contract = hours_get_work_hours_seconds($data, $dayNumber);
        $start = hours_parse_timespan((string) $day['StartTime']);
        $day['BreakMinutes'] = 0;
        $day['EndTime'] = hours_format_timespan_from_seconds($start + $contract);
        $day['HomeWorkDriven'] = hours_default_office_for_date($data, $date);
        $day = hours_enrich_day_data($day);
        $data['SavedDays'][hours_day_key($date)] = $day;
    }

    return $day;
}

/**
 * Backfill all missing previous days until an entered day is found.
 * Useful when opening Monday after Friday was the last entered day.
 *
 * @param array<string, mixed> $data
 */
function hours_backfill_previous_missing_days(array &$data, DateTimeInterface $date): void
{
    if (hours_day_exists($data, $date)) {
        return;
    }

    $missingDays = [];
    $cursor = DateTimeImmutable::createFromInterface($date)->modify('-1 day');
    $limit = 370;

    while ($limit-- > 0) {
        if (hours_day_exists($data, $cursor)) {
            break;
        }
        $missingDays[] = $cursor;
        $cursor = $cursor->modify('-1 day');
    }

    if ($missingDays === []) {
        return;
    }

    for ($i = count($missingDays) - 1; $i >= 0; $i--) {
        hours_ensure_day_initialized($data, $missingDays[$i]);
    }
}

function hours_storage_path(string $email): string
{
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Ongeldig e-mailadres voor urenopslag.');
    }

    return HOURS_CACHE_DIR . '/' . $email . '.json';
}

/**
 * @return list<string>
 */
function hours_list_user_emails(): array
{
    if (!is_dir(HOURS_CACHE_DIR)) {
        return [];
    }

    $emails = [];
    foreach (glob(HOURS_CACHE_DIR . '/*.json') ?: [] as $path) {
        $name = basename($path);
        if (!str_ends_with(strtolower($name), '.json')) {
            continue;
        }
        $email = substr($name, 0, -5);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[] = strtolower($email);
        }
    }

    sort($emails, SORT_STRING);
    return $emails;
}

/**
 * Load existing hours file without creating a new empty one.
 *
 * @return array<string, mixed>|null
 */
function hours_load_existing(string $email): ?array
{
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    $path = HOURS_CACHE_DIR . '/' . $email . '.json';
    if (!is_file($path)) {
        return null;
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }

    $data = array_merge(hours_default_save_data('', $email), $decoded);
    if (!is_array($data['MonthExtraTicks'] ?? null)) {
        $data['MonthExtraTicks'] = [];
    }
    if (!is_array($data['SavedDays'] ?? null)) {
        $data['SavedDays'] = [];
    }
    $data['DefaultOfficeDays'] = hours_normalize_default_office_days($data['DefaultOfficeDays'] ?? null);
    hours_ensure_default_office_history($data);

    foreach ($data['SavedDays'] as $dayKey => $day) {
        if (is_array($day)) {
            $data['SavedDays'][$dayKey] = hours_enrich_day_data($day);
        }
    }

    return $data;
}

/**
 * Users that already have at least one saved day (no empty auto-created files).
 *
 * @return list<string>
 */
function hours_list_users_with_data(): array
{
    $emails = [];
    foreach (hours_list_user_emails() as $email) {
        $data = hours_load_existing($email);
        if ($data === null) {
            continue;
        }
        if (!empty($data['SavedDays']) && is_array($data['SavedDays'])) {
            $emails[] = $email;
            continue;
        }
        if (function_exists('janus_light_setup_done') && janus_light_setup_done($email)) {
            $emails[] = $email;
        }
    }

    return $emails;
}

/**
 * @return array<string, mixed>
 */
function hours_load(string $email, string $userName = ''): array
{
    if (!is_dir(HOURS_CACHE_DIR)) {
        @mkdir(HOURS_CACHE_DIR, 0775, true);
    }

    $path = hours_storage_path($email);
    $email = strtolower(trim($email));

    if (!is_file($path)) {
        $data = hours_default_save_data($userName, $email);
        hours_save($email, $data);

        return $data;
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        $data = hours_default_save_data($userName, $email);
        hours_save($email, $data);

        return $data;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $data = hours_default_save_data($userName, $email);
        hours_save($email, $data);

        return $data;
    }

    $data = array_merge(hours_default_save_data($userName, $email), $decoded);
    if (!is_array($data['MonthExtraTicks'] ?? null)) {
        $data['MonthExtraTicks'] = [];
    }
    if (!is_array($data['SavedDays'] ?? null)) {
        $data['SavedDays'] = [];
    }
    $data['DefaultOfficeDays'] = hours_normalize_default_office_days($data['DefaultOfficeDays'] ?? null);
    hours_ensure_default_office_history($data);

    if ($userName !== '' && trim((string) ($data['UserName'] ?? '')) === '') {
        $data['UserName'] = $userName;
    }
    if (trim((string) ($data['UserEmail'] ?? '')) === '') {
        $data['UserEmail'] = $email;
    }

    foreach ($data['SavedDays'] as $dayKey => $day) {
        if (is_array($day)) {
            $data['SavedDays'][$dayKey] = hours_enrich_day_data($day);
        }
    }

    return $data;
}

/**
 * @param array<string, mixed> $data
 */
function hours_save(string $email, array $data): void
{
    if (!is_dir(HOURS_CACHE_DIR)) {
        @mkdir(HOURS_CACHE_DIR, 0775, true);
    }

    foreach ($data['SavedDays'] as $dayKey => $day) {
        if (is_array($day)) {
            $data['SavedDays'][$dayKey] = hours_enrich_day_data($day);
        }
    }

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Uren data kon niet worden geserialiseerd.');
    }

    $path = hours_storage_path($email);
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('Uren data kon niet worden weggeschreven.');
    }
    if (!rename($tmp, $path)) {
        @unlink($path);
        if (!rename($tmp, $path)) {
            throw new RuntimeException('Uren data kon niet worden opgeslagen.');
        }
    }
}

/**
 * @param array<string, mixed> $data
 */
function hours_get_saved_month_extra_seconds(array $data, int $year, int $month): int
{
    $key = hours_month_key($month, $year);
    $ticks = $data['MonthExtraTicks'][$key] ?? null;
    if ($ticks === null || $ticks === '') {
        return 0;
    }

    // .NET TimeSpan.Ticks: 10_000_000 per second
    return (int) round(((float) $ticks) / 10000000);
}

/**
 * @param array<string, mixed> $data
 */
function hours_set_saved_month_extra(array &$data, int $year, int $month, int $seconds): void
{
    if (!is_array($data['MonthExtraTicks'] ?? null)) {
        $data['MonthExtraTicks'] = [];
    }
    $data['MonthExtraTicks'][hours_month_key($month, $year)] = (int) ($seconds * 10000000);
}

/**
 * Mirrors MainForm.CalculateExtraHours.
 *
 * @param array<string, mixed> $data
 */
function hours_calculate_extra_seconds(array &$data, DateTimeInterface $selectedDay, bool $previousMonth = false): int
{
    $year = (int) $selectedDay->format('Y');
    $month = (int) $selectedDay->format('n');

    if ($previousMonth) {
        $month -= 1;
        if ($month === 0) {
            $month = 12;
            $year -= 1;
        }
    }

    $firstDay = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
    $lastDay = $firstDay->modify('last day of this month');
    $today = new DateTimeImmutable('today');
    $extraSeconds = 0;

    for ($day = $firstDay; $day <= $lastDay; $day = $day->modify('+1 day')) {
        $dayNumber = hours_weekday_index($day);
        $contract = hours_get_work_hours_seconds($data, $dayNumber);

        if (!hours_day_exists($data, $day)) {
            continue;
        }

        $dayData = hours_get_day($data, $day);
        $worked = hours_parse_timespan((string) $dayData['WorkedTime']);

        if (
            ($contract > 0 || $worked > 0)
            && empty($dayData['isHoliday'])
            && empty($dayData['isSickDay'])
            && $day <= $today
        ) {
            $extraSeconds += ($worked - $contract);
        }
    }

    hours_set_saved_month_extra($data, $year, $month, $extraSeconds);

    if ($extraSeconds < 0 && $previousMonth) {
        $pmonth = $month - 1;
        $pyear = $year;
        if ($pmonth === 0) {
            $pmonth = 12;
            $pyear -= 1;
        }

        $needed = -$extraSeconds;
        $available = hours_get_saved_month_extra_seconds($data, $pyear, $pmonth);

        if ($available >= $needed) {
            $extraSeconds = 0;
        } else {
            $extraSeconds += $available;
        }
    }

    return $extraSeconds;
}

/**
 * Format like WinForms: +08:30 / -01:15
 */
function hours_format_extra_label(int $seconds): string
{
    $sign = $seconds < 0 ? '-' : '+';
    $seconds = abs($seconds);
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);

    return sprintf('%s%02d:%02d', $sign, $h, $m);
}

/**
 * Kilometers for one PDF row: woon-werk (heen+terug) + extra.
 *
 * @param array<string, mixed> $day
 * @param array<string, mixed> $data
 */
function hours_day_driven_km(array $day, array $data): float
{
    if (!empty($day['isHoliday']) || !empty($day['isSickDay'])) {
        return 0.0;
    }

    $km = (float) ($day['Kilometers'] ?? 0);
    if (!empty($day['HomeWorkDriven'])) {
        $km += (float) ($data['KilometerHomeWork'] ?? 0) * 2;
    }

    return $km;
}

function hours_format_km_nl(float $km): string
{
    return number_format($km, 1, ',', '') . ' km';
}

/**
 * Rows for classic Janus PDF (alleen dagen met uren/km/vakantie/ziek).
 *
 * @param array<string, mixed> $data
 * @return list<array{date: DateTimeImmutable, day: array<string, mixed>}>
 */
function hours_pdf_collect_rows(array $data, DateTimeInterface $firstDay, DateTimeInterface $lastDay): array
{
    $rows = [];
    $first = DateTimeImmutable::createFromInterface($firstDay)->setTime(0, 0, 0);
    $last = DateTimeImmutable::createFromInterface($lastDay)->setTime(0, 0, 0);

    for ($day = $first; $day <= $last; $day = $day->modify('+1 day')) {
        if (!hours_day_exists($data, $day)) {
            continue;
        }

        $dayData = hours_get_day($data, $day);
        $worked = hours_parse_timespan((string) $dayData['WorkedTime']);
        $km = hours_day_driven_km($dayData, $data);
        $isHoliday = !empty($dayData['isHoliday']);
        $isSick = !empty($dayData['isSickDay']);

        if ($worked <= 0 && $km <= 0 && !$isHoliday && !$isSick) {
            continue;
        }

        $rows[] = ['date' => $day, 'day' => $dayData];
    }

    return $rows;
}

function hours_current_user_email(): string
{
    return strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
}

function hours_current_user_name(): string
{
    $name = trim((string) ($_SESSION['user']['name'] ?? ''));
    if ($name !== '') {
        return $name;
    }

    $email = hours_current_user_email();
    if ($email !== '') {
        $local = explode('@', $email)[0] ?? '';

        return $local !== '' ? $local : $email;
    }

    return '';
}

/**
 * Display name for PDFs/UI: session → savedata (als geen bedrijfsnaam) → fallback.
 *
 * @param array<string, mixed> $data
 */
function hours_resolve_user_name(array $data, string $email = ''): string
{
    $sessionName = trim((string) ($_SESSION['user']['name'] ?? ''));
    if ($sessionName !== '' && ($email === '' || strcasecmp($email, hours_current_user_email()) === 0)) {
        return $sessionName;
    }

    $email = strtolower(trim($email !== '' ? $email : hours_current_user_email()));
    $saved = trim((string) ($data['UserName'] ?? ''));

    global $mailList;
    $company = '';
    if ($email !== '' && is_array($mailList ?? null) && isset($mailList[$email])) {
        $company = trim((string) $mailList[$email]);
    }

    if ($saved !== '' && ($company === '' || strcasecmp($saved, $company) !== 0)) {
        return $saved;
    }

    if ($email !== '') {
        $local = explode('@', $email)[0] ?? '';
        if ($local !== '') {
            return $local;
        }
    }

    return hours_current_user_name();
}

/**
 * @param array<string, mixed> $data
 */
function hours_count_office_days_in_range(array $data, DateTimeInterface $start, DateTimeInterface $end): int
{
    $count = 0;
    $first = DateTimeImmutable::createFromInterface($start)->setTime(0, 0, 0);
    $last = DateTimeImmutable::createFromInterface($end)->setTime(0, 0, 0);

    for ($day = $first; $day <= $last; $day = $day->modify('+1 day')) {
        if (hours_is_effective_office_day($data, $day)) {
            $count++;
        }
    }

    return $count;
}

/**
 * True when the range has an explicit SavedDay or an effective office day.
 */
function hours_has_any_day_in_range(array $data, DateTimeInterface $start, DateTimeInterface $end): bool
{
    $first = DateTimeImmutable::createFromInterface($start)->setTime(0, 0, 0);
    $last = DateTimeImmutable::createFromInterface($end)->setTime(0, 0, 0);

    for ($day = $first; $day <= $last; $day = $day->modify('+1 day')) {
        if (hours_day_exists($data, $day) || hours_is_effective_office_day($data, $day)) {
            return true;
        }
    }

    return false;
}

/**
 * Missing weekdays in range where no data exists at all.
 *
 * @return list<string>
 */
function hours_missing_days_in_range(array $data, DateTimeInterface $start, DateTimeInterface $end): array
{
    $missing = [];
    $first = DateTimeImmutable::createFromInterface($start)->setTime(0, 0, 0);
    $last = DateTimeImmutable::createFromInterface($end)->setTime(0, 0, 0);

    for ($day = $first; $day <= $last; $day = $day->modify('+1 day')) {
        $dayNumber = hours_weekday_index($day);
        if (hours_get_work_hours_seconds($data, $dayNumber) <= 0) {
            continue;
        }
        if (!hours_day_exists($data, $day)) {
            $missing[] = $day->format('Y-m-d');
        }
    }

    return $missing;
}

/**
 * Dutch month abbreviations in lowercase.
 */
function janus_nl_month_abbrev(int $month): string
{
    $months = [
        1 => 'jan', 2 => 'feb', 3 => 'mrt', 4 => 'apr',
        5 => 'mei', 6 => 'jun', 7 => 'jul', 8 => 'aug',
        9 => 'sep', 10 => 'okt', 11 => 'nov', 12 => 'dec',
    ];

    return $months[$month] ?? '';
}

/**
 * UI format requested by user: "17 dec 2026"
 */
function janus_nl_date_ui(DateTimeInterface $date): string
{
    $d = (int) $date->format('j'); // no leading zero
    $m = (int) $date->format('n');
    $y = (int) $date->format('Y');

    return sprintf('%d %s %d', $d, janus_nl_month_abbrev($m), $y);
}
