<?php
require __DIR__ . '/hours_data.php';
require __DIR__ . '/hours_pdf.php';

$data = hours_load('tfalken@kvt.nl');
$data['UserName'] = 'Tim Falken';
$first = new DateTimeImmutable('2026-07-01');
$last = new DateTimeImmutable('2026-07-31');
$logo = janus_logo_pdf_path('');

$html = hours_build_pdf_html($data, $first, $last, 'Tim Falken', 'tfalken@kvt.nl', 'juli 2026', $logo);
echo (strpos($html, '<table class="hours-table"') !== false ? 'TABLE' : 'NO_TABLE') . PHP_EOL;
echo (strpos($html, '<tbody>') !== false ? 'TBODY' : 'NO_TBODY') . PHP_EOL;
echo (strpos($html, 'tr class="alt"') !== false ? 'ALT_ROWS' : 'NO_ALT') . PHP_EOL;
echo 'logo=' . basename($logo) . PHP_EOL;

$pdf = hours_build_pdf($data, $first, $last, 'Tim Falken', 'tfalken@kvt.nl', 'juli 2026', $logo);
file_put_contents(__DIR__ . '/cache/hours/compare-fixed.pdf', $pdf);
echo 'bytes=' . strlen($pdf) . PHP_EOL;
