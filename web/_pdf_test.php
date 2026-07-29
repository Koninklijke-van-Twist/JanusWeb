<?php
require __DIR__ . '/hours_data.php';
require __DIR__ . '/hours_pdf.php';

$email = 'test@kvt.nl';
$data = hours_default_save_data('Tim Falken', $email);
$data['KilometerHomeWork'] = 33;
$day = new DateTimeImmutable('2026-07-29');
$key = hours_day_key($day);
$data['SavedDays'][$key] = hours_enrich_day_data([
    'StartTime' => '08:05:00',
    'EndTime' => '16:58:00',
    'BreakMinutes' => 15,
    'Kilometers' => 0,
    'HomeWorkDriven' => true,
    'isHoliday' => false,
    'isSickDay' => false,
]);
// Add a few more weekdays so table looks real
for ($i = 1; $i <= 5; $i++) {
    $d = new DateTimeImmutable('2026-07-0' . $i);
    if (hours_weekday_index($d) > 4) {
        continue;
    }
    $k = hours_day_key($d);
    $data['SavedDays'][$k] = hours_enrich_day_data([
        'StartTime' => '08:00:00',
        'EndTime' => '16:30:00',
        'BreakMinutes' => 30,
        'Kilometers' => 0,
        'HomeWorkDriven' => true,
        'isHoliday' => false,
        'isSickDay' => false,
    ]);
}

$first = new DateTimeImmutable('2026-07-01');
$last = new DateTimeImmutable('2026-07-31');
$logo = is_file(__DIR__ . '/kvt-logo.jpg') ? (__DIR__ . '/kvt-logo.jpg') : (__DIR__ . '/kvt-logo.png');

echo 'chromium: ' . (janus_find_chromium() ?? 'none') . PHP_EOL;

try {
    $pdf = hours_build_pdf($data, $first, $last, 'Tim Falken', $email, janus_nl_month_year($first), $logo);
    $out = __DIR__ . '/cache/hours/_test_export.pdf';
    file_put_contents($out, $pdf);
    echo 'ok bytes=' . strlen($pdf) . ' file=' . $out . ' head=' . substr($pdf, 0, 8) . PHP_EOL;
} catch (Throwable $e) {
    echo 'FAIL: ' . $e->getMessage() . PHP_EOL;
}
