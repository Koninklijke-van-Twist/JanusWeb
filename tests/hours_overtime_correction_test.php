<?php

declare(strict_types=1);

/**
 * Overurencorrecties blijven in het totaal, ook als de maand buiten het rollende venster valt.
 * Run: php tests/hours_overtime_correction_test.php
 */

/**
 * Includes/requires
 */
require_once dirname(__DIR__) . '/web/hours_data.php';

/**
 * Variabelen
 */
$failures = 0;
$email = 'overtime-correction-test@example.com';
$at = new DateTimeImmutable('2026-01-15 10:00:00', new DateTimeZone('Europe/Amsterdam'));

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

function rejects(callable $fn): bool
{
    try {
        $fn();
    } catch (InvalidArgumentException $e) {
        return true;
    }

    return false;
}

/**
 * Test
 */
$data = hours_default_save_data('Test', $email);
check(hours_overtime_corrections_total_seconds($data) === 0, 'leeg: totaal 0');

hours_add_overtime_correction($data, 5400, "  te veel\n gewerkt ", $email, $at);
hours_add_overtime_correction($data, -1800, 'afgetrokken', $email, $at);
check(hours_overtime_corrections_total_seconds($data) === 3600, 'totaal is som van correcties');
check($data['OvertimeCorrections'][0]['Reason'] === 'te veel gewerkt', 'reden wordt genormaliseerd');
check($data['OvertimeCorrections'][0]['Date'] === '15-01-2026 10:00:00', 'datum dd-MM-yyyy HH:mm:ss');

// Herberekenen van maanden raakt de correcties niet (venster = huidige + vorige maand).
hours_calculate_extra_seconds($data, new DateTimeImmutable('2026-06-01'), false);
hours_calculate_extra_seconds($data, new DateTimeImmutable('2026-06-01'), true);
check(hours_overtime_corrections_total_seconds($data) === 3600, 'correcties onafhankelijk van maandherberekening');

check(rejects(static function () use (&$data, $email): void { hours_add_overtime_correction($data, 0, 'x', $email); }), 'nul seconden geweigerd');
check(rejects(static function () use (&$data, $email): void { hours_add_overtime_correction($data, 60, '  ', $email); }), 'lege reden geweigerd');
check(rejects(static function () use (&$data, $email): void { hours_add_overtime_correction($data, 60, str_repeat('a', 201), $email); }), 'te lange reden geweigerd');
check(rejects(static function () use (&$data, $email): void { hours_add_overtime_correction($data, HOURS_MAX_CORRECTION_SECONDS + 1, 'x', $email); }), 'te grote correctie geweigerd');
check(count($data['OvertimeCorrections']) === 2, 'geweigerde correcties niet opgeslagen');

hours_save($email, $data);
$loaded = hours_load_existing($email);
@unlink(hours_storage_path($email));
check($loaded !== null && hours_overtime_corrections_total_seconds($loaded) === 3600, 'correcties overleven opslaan en laden');

check(hours_overtime_corrections_total_seconds(['OvertimeCorrections' => 'kapot']) === 0, 'ongeldige data geeft 0');

exit($failures === 0 ? 0 : 1);
