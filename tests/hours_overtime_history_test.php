<?php

declare(strict_types=1);

/**
 * Overuren-historie: totaal over alle maanden, ook ouder dan het rollende venster.
 * Run: php tests/hours_overtime_history_test.php
 */

/**
 * Includes/requires
 */
require_once dirname(__DIR__) . '/web/hours_data.php';

/**
 * Variabelen
 */
$failures = 0;
$tz = new DateTimeZone('Europe/Amsterdam');
$now = new DateTimeImmutable('now', $tz);

/**
 * Functies
 */
function check(bool $condition, string $name): void
{
    global $failures;
    if ($condition) {
        echo "OK  $name\n";
        return;
    }
    $failures++;
    echo "FAIL $name\n";
}

/**
 * @param list<array{cumulative: int}> $history
 */
function array_last_cumulative(array $history): int
{
    return $history[count($history) - 1]['cumulative'];
}

/**
 * @return array<string, mixed>
 */
function day_with_overtime(array $data, DateTimeInterface $date, int $extraMinutes): array
{
    $contract = hours_get_work_hours_seconds($data, hours_weekday_index($date));
    $day = hours_default_day_data();
    $day['StartTime'] = '08:00:00';
    $day['BreakMinutes'] = 0;
    $day['EndTime'] = hours_format_timespan_from_seconds(8 * 3600 + $contract + $extraMinutes * 60);

    return $day;
}

/**
 * Test
 */
$data = hours_default_save_data('Test', 'history-test@example.com');
$data['SavedDays'] = [];
check(count(hours_extra_history($data)) === 1 && hours_extra_history($data)[0]['cumulative'] === 0, 'leeg: alleen huidige maand met 0');

// Een werkdag (ma-vr) 5 en 8 maanden geleden, met 60 en 30 minuten overuren.
foreach ([[5, 60], [8, 30]] as [$monthsAgo, $minutes]) {
    $date = $now->modify("first day of -$monthsAgo months");
    while ((int) $date->format('N') > 5) {
        $date = $date->modify('+1 day');
    }
    $data['SavedDays'][hours_day_key($date)] = day_with_overtime($data, $date, $minutes);
}
$history = hours_extra_history($data);
check(array_last_cumulative($history) === 5400, 'totaal telt maanden buiten het 2-maandsvenster mee');
check($history[0]['seconds'] === 1800 && $history[0]['cumulative'] === 1800, 'oudste maand eerst, lopend totaal klopt');
check(count($history) === 3, 'alleen maanden met gegevens plus de huidige maand');
check(isset($data['MonthExtraTicks'][hours_month_key((int) $now->modify('-5 months')->format('n'), (int) $now->modify('first day of -5 months')->format('Y'))]), 'maandtotaal wordt opgeslagen');

// Opgeslagen maandtotaal blijft staan als het contract later wijzigt.
$data['MondayHours'] = $data['TuesdayHours'] = $data['WednesdayHours'] = $data['ThursdayHours'] = $data['FridayHours'] = '04:00:00';
check(array_last_cumulative(hours_extra_history($data)) === 5400, 'contractwijziging herschrijft oude maanden niet');

// WinForms-bestand: maandtotaal zonder dagen.
$data = hours_default_save_data('Test', 'history-test@example.com');
hours_set_saved_month_extra($data, 2020, 3, 7200);
$history = hours_extra_history($data);
check($history[0]['month'] === 3 && $history[0]['year'] === 2020 && $history[0]['seconds'] === 7200, 'maandtotaal zonder dagen telt mee');

// Oud veld OvertimeCorrections wordt genegeerd en blijft onaangeroerd.
$data['OvertimeCorrections'] = [['Seconds' => 3600]];
check(array_last_cumulative(hours_extra_history($data)) === 7200 && count($data['OvertimeCorrections']) === 1, 'OvertimeCorrections genegeerd');

exit($failures === 0 ? 0 : 1);
