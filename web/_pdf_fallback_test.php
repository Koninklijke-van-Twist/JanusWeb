<?php
require __DIR__ . '/hours_data.php';
require __DIR__ . '/hours_pdf.php';
require __DIR__ . '/SimplePdf.php';

$data = hours_default_save_data('Tim Falken', 'test@kvt.nl');
$data['KilometerHomeWork'] = 33;
$day = new DateTimeImmutable('2026-07-29');
$data['SavedDays'][hours_day_key($day)] = hours_enrich_day_data([
    'StartTime' => '08:05:00',
    'EndTime' => '16:58:00',
    'BreakMinutes' => 15,
    'Kilometers' => 12,
    'HomeWorkDriven' => true,
    'isHoliday' => false,
    'isSickDay' => false,
]);
$pdf = hours_build_pdf_simple(
    $data,
    new DateTimeImmutable('2026-07-01'),
    new DateTimeImmutable('2026-07-31'),
    'Tim Falken',
    'test@kvt.nl',
    'juli 2026',
    __DIR__ . '/kvt-logo.jpg'
);
file_put_contents(__DIR__ . '/cache/hours/_fallback.pdf', $pdf);
echo strlen($pdf) . ' ' . substr($pdf, 0, 8) . "\n";
