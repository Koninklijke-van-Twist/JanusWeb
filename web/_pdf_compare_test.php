<?php
require __DIR__ . '/hours_data.php';
require __DIR__ . '/hours_pdf.php';

$email = 'tfalken@kvt.nl';
$data = hours_load($email);
$data['UserName'] = 'Tim Falken';
$data['UserEmail'] = $email;
hours_save($email, $data);

$first = new DateTimeImmutable('2026-07-01');
$last = new DateTimeImmutable('2026-07-31');
$pdf = hours_build_pdf(
    $data,
    $first,
    $last,
    'Tim Falken',
    $email,
    janus_nl_month_year($first),
    __DIR__ . '/kvt-logo.jpg'
);
file_put_contents(__DIR__ . '/../nieuwe janus.pdf', $pdf);

$rows = hours_pdf_collect_rows($data, $first, $last);
echo 'days_total=' . count($data['SavedDays']) . ' pdf_rows=' . count($rows) . "\n";
foreach (array_slice($rows, 0, 8) as $r) {
    echo janus_nl_date_pdf_row($r['date']) . ' | ' . (
        !empty($r['day']['isHoliday']) ? 'Vakantiedag' : $r['day']['WorkedString']
    ) . ' | ' . (
        !empty($r['day']['isHoliday']) ? 'N.v.t.' : hours_format_km_nl(hours_day_driven_km($r['day'], $data))
    ) . "\n";
}
echo 'pdf=' . strlen($pdf) . "\n";
