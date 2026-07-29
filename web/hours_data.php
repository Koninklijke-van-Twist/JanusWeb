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
        $day['HomeWorkDriven'] = $contract > 0;
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
        if (!hours_day_exists($data, $day)) {
            continue;
        }
        $row = hours_get_day($data, $day);
        if (!empty($row['HomeWorkDriven']) && empty($row['isHoliday']) && empty($row['isSickDay'])) {
            $count++;
        }
    }

    return $count;
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
