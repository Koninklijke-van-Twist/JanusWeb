<?php

/**
 * Render HTML to PDF via Edge/Chrome headless (same approach as Horae).
 */
function janus_find_chromium(): ?string
{
    $env = getenv('JANUS_CHROMIUM_PATH');
    if (is_string($env) && $env !== '' && is_file($env)) {
        return $env;
    }

    $candidates = [
        'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
        'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
        '/usr/bin/google-chrome-stable',
        '/usr/bin/google-chrome',
        '/usr/bin/chromium-browser',
        '/usr/bin/chromium',
    ];

    foreach ($candidates as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return null;
}

function janus_html_to_pdf(string $html): string
{
    $chrome = janus_find_chromium();
    if ($chrome === null) {
        throw new RuntimeException('Chrome/Edge niet gevonden voor PDF-export.');
    }

    $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'janus-pdf-' . bin2hex(random_bytes(4));
    if (!@mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
        throw new RuntimeException('Tijdelijke map aanmaken mislukt.');
    }

    $htmlFile = $tmpDir . DIRECTORY_SEPARATOR . 'report.html';
    $pdfFile = $tmpDir . DIRECTORY_SEPARATOR . 'report.pdf';

    // Copy fonts next to HTML so @font-face can use relative file URLs (more reliable than huge data-URIs).
    $fontDir = $tmpDir . DIRECTORY_SEPARATOR . 'fonts';
    @mkdir($fontDir, 0775, true);
    foreach (['Verdana-Regular.ttf', 'Verdana-Bold.ttf'] as $fontName) {
        $src = __DIR__ . '/fonts/' . $fontName;
        if (is_file($src)) {
            @copy($src, $fontDir . DIRECTORY_SEPARATOR . $fontName);
        }
    }

    // Rewrite embedded data-URI font faces to local relative paths when present in template helpers.
    $html = str_replace(
        [
            'url(data:font/ttf;base64,FONT_REGULAR)',
            'url(data:font/ttf;base64,FONT_BOLD)',
        ],
        [
            'url(fonts/Verdana-Regular.ttf)',
            'url(fonts/Verdana-Bold.ttf)',
        ],
        $html
    );

    if (file_put_contents($htmlFile, $html) === false) {
        throw new RuntimeException('HTML schrijven mislukt.');
    }

    $htmlPath = str_replace('\\', '/', (string) realpath($htmlFile));
    $uri = 'file:///' . $htmlPath;

    // Strong cache busting: unique browser profile + cache dir per export.
    $userDataDir = $tmpDir . DIRECTORY_SEPARATOR . 'chrome-profile';
    $cacheDir = $tmpDir . DIRECTORY_SEPARATOR . 'chrome-cache';
    @mkdir($userDataDir, 0775, true);
    @mkdir($cacheDir, 0775, true);

    $cmd = sprintf(
        '%s --headless --disable-gpu --no-sandbox --disable-dev-shm-usage --allow-file-access-from-files --user-data-dir=%s --disk-cache-dir=%s --disable-application-cache --disable-http-cache --run-all-compositor-stages-before-draw --virtual-time-budget=20000 --no-pdf-header-footer --print-to-pdf=%s %s 2>&1',
        escapeshellarg($chrome),
        escapeshellarg($userDataDir),
        escapeshellarg($cacheDir),
        escapeshellarg($pdfFile),
        escapeshellarg($uri)
    );

    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);

    $pdf = (is_file($pdfFile) && filesize($pdfFile) > 128) ? file_get_contents($pdfFile) : false;

    // Cleanup temp dir
    foreach (glob($fontDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
        @unlink($f);
    }
    foreach (glob($cacheDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($fontDir);
    @rmdir($cacheDir);
    @rmdir($userDataDir);
    @unlink($htmlFile);
    @unlink($pdfFile);
    @rmdir($tmpDir);

    if ($exitCode !== 0 || $pdf === false || $pdf === '') {
        $message = trim(implode("\n", $output));
        throw new RuntimeException('PDF-generatie mislukt' . ($message !== '' ? ': ' . $message : ''));
    }

    return $pdf;
}

/**
 * Dutch month/day names (no intl extension required).
 */
function janus_nl_month(int $month): string
{
    $months = [
        1 => 'januari', 2 => 'februari', 3 => 'maart', 4 => 'april',
        5 => 'mei', 6 => 'juni', 7 => 'juli', 8 => 'augustus',
        9 => 'september', 10 => 'oktober', 11 => 'november', 12 => 'december',
    ];

    return $months[$month] ?? '';
}

function janus_nl_weekday(DateTimeInterface $date): string
{
    // PHP w: 0=Sun … 6=Sat — match .NET dddd in nl-NL (lowercase)
    $days = ['zondag', 'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag'];

    return $days[(int) $date->format('w')] ?? '';
}

function janus_nl_date_pdf_row(DateTimeInterface $date): string
{
    return sprintf(
        '%02d %s (%s)',
        (int) $date->format('d'),
        janus_nl_month((int) $date->format('n')),
        janus_nl_weekday($date)
    );
}

function janus_nl_month_year(DateTimeInterface $date): string
{
    return janus_nl_month((int) $date->format('n')) . ' ' . $date->format('Y');
}

function janus_nl_long_date(DateTimeInterface $date): string
{
    return sprintf(
        '%02d %s %s',
        (int) $date->format('d'),
        janus_nl_month((int) $date->format('n')),
        $date->format('Y')
    );
}

/**
 * Logo for PDF/HTML: transparent PNGs render black in headless Chrome — use white-backed asset.
 */
function janus_logo_pdf_path(string $logoPath = ''): string
{
    $white = __DIR__ . '/kvt-logo-white.png';
    if (is_file($white)) {
        return $white;
    }
    if ($logoPath !== '' && is_file($logoPath) && !str_ends_with(strtolower($logoPath), '.jpg')) {
        return $logoPath;
    }

    $png = __DIR__ . '/kvt-logo.png';
    if (is_file($png)) {
        return $png;
    }

    return $logoPath;
}

function janus_logo_pdf_html(string $logoPath = ''): string
{
    $path = janus_logo_pdf_path($logoPath);
    if ($path === '' || !is_file($path)) {
        return '';
    }

    $bin = file_get_contents($path);
    if ($bin === false) {
        return '';
    }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = ($ext === 'jpg' || $ext === 'jpeg') ? 'image/jpeg' : 'image/png';

    return '<img class="logo" src="data:' . $mime . ';base64,' . base64_encode($bin) . '" alt="">';
}

/**
 * Build HTML matching classic Janus PDF (oude janus.pdf layout).
 *
 * @param array<string, mixed> $data
 */
function hours_build_pdf_html(
    array $data,
    DateTimeInterface $firstDay,
    DateTimeInterface $lastDay,
    string $userName,
    string $userEmail,
    string $periodLabel,
    string $logoPath = ''
): string {
    $rows = hours_pdf_collect_rows($data, $firstDay, $lastDay);
    $extraSeconds = 0;
    $totalKm = 0.0;

    $rowHtml = '';
    $shade = false;
    foreach ($rows as $row) {
        /** @var DateTimeImmutable $date */
        $date = $row['date'];
        /** @var array<string, mixed> $day */
        $day = $row['day'];

        $dayNumber = hours_weekday_index($date);
        $contract = hours_get_work_hours_seconds($data, $dayNumber);
        if (empty($day['isHoliday'])) {
            $worked = hours_parse_timespan((string) $day['WorkedTime']);
            $extraSeconds += ($worked - $contract);
        }

        $hoursLabel = !empty($day['isHoliday'])
            ? 'Vakantiedag'
            : (!empty($day['isSickDay']) ? 'Ziek' : (string) $day['WorkedString']);

        $dayKm = hours_day_driven_km($day, $data);
        $kmLabel = (!empty($day['isHoliday']) || !empty($day['isSickDay']))
            ? 'N.v.t.'
            : hours_format_km_nl($dayKm);

        if (empty($day['isHoliday']) && empty($day['isSickDay'])) {
            $totalKm += $dayKm;
        }

        $rowStyle = $shade ? ' style="background:#d3d3d3;"' : '';
        $rowHtml .= sprintf(
            '<tr%s>'
            . '<td style="height:25pt;padding:0 2pt;text-align:left;vertical-align:middle;white-space:nowrap;overflow:hidden;">%s</td>'
            . '<td style="height:25pt;padding:0 2pt;text-align:left;vertical-align:middle;white-space:nowrap;overflow:hidden;">%s</td>'
            . '<td style="height:25pt;padding:0 2pt;text-align:left;vertical-align:middle;white-space:nowrap;overflow:hidden;">%s</td>'
            . '</tr>',
            $rowStyle,
            htmlspecialchars(janus_nl_date_pdf_row($date), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($hoursLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($kmLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
        $shade = !$shade;
    }

    $footerLeft = '';
    if ($extraSeconds > 0) {
        $h = (int) floor($extraSeconds / 3600);
        $m = (int) floor(($extraSeconds % 3600) / 60);
        $minuteLabel = $m === 1 ? 'minuut' : 'minuten';
        $footerLeft .= '<div class="footer-line">Overgewerkt: '
            . htmlspecialchars(sprintf('%d uur, %d %s', $h, $m, $minuteLabel), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</div>';
    }
    $footerLeft .= '<div class="footer-line">Totaal kilometers gereden: '
        . htmlspecialchars(number_format($totalKm, 1, ',', ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '</div>';

    $active = [
        hours_parse_timespan((string) ($data['MondayHours'] ?? '00:00:00')) > 0,
        hours_parse_timespan((string) ($data['TuesdayHours'] ?? '00:00:00')) > 0,
        hours_parse_timespan((string) ($data['WednesdayHours'] ?? '00:00:00')) > 0,
        hours_parse_timespan((string) ($data['ThursdayHours'] ?? '00:00:00')) > 0,
        hours_parse_timespan((string) ($data['FridayHours'] ?? '00:00:00')) > 0,
        hours_parse_timespan((string) ($data['SaturdayHours'] ?? '00:00:00')) > 0,
        hours_parse_timespan((string) ($data['SundayHours'] ?? '00:00:00')) > 0,
    ];
    $labels = ['Ma', 'Di', 'Wo', 'Do', 'Vr', 'Za', 'Zo'];
    $gridHtml = '';
    for ($i = 0; $i < 7; $i++) {
        $cls = $active[$i] ? 'day on' : 'day off';
        $gridHtml .= '<div class="' . $cls . '">' . $labels[$i] . '</div>';
    }

    $logoHtml = janus_logo_pdf_html($logoPath);

    $fontFace = '@font-face{font-family:Verdana;font-weight:400;src:url(fonts/Verdana-Regular.ttf) format("truetype");}'
        . '@font-face{font-family:Verdana;font-weight:700;src:url(fonts/Verdana-Bold.ttf) format("truetype");}';

    $titleEsc = htmlspecialchars($userName . ', ' . $periodLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $emailEsc = htmlspecialchars($userEmail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $logoHtml = str_replace(
        '<img class="logo"',
        '<img style="position:absolute;left:50pt;top:20pt;width:200pt;height:50pt;object-fit:contain;object-position:left center;background:#fff;"',
        $logoHtml
    );

    $gridHtml = str_replace(
        ['class="day on"', 'class="day off"', 'class="day"'],
        [
            'style="width:20pt;height:15pt;display:flex;align-items:center;justify-content:center;font-size:8pt;font-weight:400;background:#0099CC;color:#fff;"',
            'style="width:20pt;height:15pt;display:flex;align-items:center;justify-content:center;font-size:8pt;font-weight:400;background:#d3d3d3;color:#808080;"',
            'style="width:20pt;height:15pt;display:flex;align-items:center;justify-content:center;font-size:8pt;font-weight:400;"'
        ],
        $gridHtml
    );

    return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
        . $fontFace
        . '@page { size: A4; margin: 0; }'
        . 'html,body{margin:0;padding:0;width:210mm;height:297mm;font-family:Verdana,Arial,sans-serif;color:#00529B;background:#fff;}'
        . '</style></head><body>'
        . '<div style="position:relative;width:210mm;height:297mm;overflow:hidden;">'
        . $logoHtml
        . '<div style="position:absolute;left:50pt;top:100pt;font-size:24pt;font-weight:400;line-height:30pt;">' . $titleEsc . '</div>'
        . '<div style="position:absolute;left:50pt;top:130pt;font-size:12pt;font-weight:400;line-height:20pt;">' . $emailEsc . '</div>'
        . '<div style="position:absolute;left:50pt;top:160pt;width:495pt;">'
        . '<table style="width:100%;border-collapse:collapse;table-layout:fixed;font-size:12pt;">'
        . '<colgroup>'
        . '<col style="width:200pt;">'
        . '<col style="width:150pt;">'
        . '<col style="width:145pt;">'
        . '</colgroup>'
        . '<thead><tr>'
        . '<th style="height:25pt;padding:0 2pt;text-align:left;vertical-align:middle;font-weight:700;">Datum</th>'
        . '<th style="height:25pt;padding:0 2pt;text-align:left;vertical-align:middle;font-weight:700;">Gewerkte uren</th>'
        . '<th style="height:25pt;padding:0 2pt;text-align:left;vertical-align:middle;font-weight:700;">Gereden Kilometers</th>'
        . '</tr></thead>'
        . '<tbody>' . $rowHtml . '</tbody>'
        . '</table>'
        . '</div>'
        . '<div style="position:absolute;left:50pt;top:785pt;font-size:12pt;">' . $footerLeft . '</div>'
        . '<div style="position:absolute;right:50pt;top:792pt;display:flex;align-items:center;gap:10pt;">'
        . '<div style="font-size:12pt;white-space:nowrap;">Vaste Werkdagen:</div>'
        . '<div style="width:95pt;display:grid;grid-template-columns:repeat(4, 20pt);grid-template-rows:15pt 15pt;gap:5pt;">' . $gridHtml . '</div>'
        . '</div>'
        . '</div></body></html>';
}

/**
 * @param array<string, mixed> $data
 */
function hours_build_pdf(
    array $data,
    DateTimeInterface $firstDay,
    DateTimeInterface $lastDay,
    string $userName,
    string $userEmail,
    string $periodLabel,
    string $logoPath = ''
): string {
    $htmlLogo = janus_logo_pdf_path($logoPath);
    $html = hours_build_pdf_html($data, $firstDay, $lastDay, $userName, $userEmail, $periodLabel, $htmlLogo);

    try {
        return janus_html_to_pdf($html);
    } catch (Throwable $e) {
        // Keep export available when headless Chrome flags differ by machine/version.
        return hours_build_pdf_simple($data, $firstDay, $lastDay, $userName, $userEmail, $periodLabel, $htmlLogo);
    }
}

/**
 * Pure-PHP fallback matching classic Janus PDF layout.
 *
 * @param array<string, mixed> $data
 */
function hours_build_pdf_simple(
    array $data,
    DateTimeInterface $firstDay,
    DateTimeInterface $lastDay,
    string $userName,
    string $userEmail,
    string $periodLabel,
    string $logoPath = ''
): string {
    require_once __DIR__ . '/SimplePdf.php';

    $pdf = new SimplePdf();
    $pdf->addPage();

    if ($logoPath !== '' && is_file($logoPath)) {
        $pdf->setImageJpeg($logoPath, 50, 20, 200, 50);
    }

    $kvt = [0, 82, 155];
    $pdf->setTextColor($kvt[0], $kvt[1], $kvt[2]);
    $pdf->text(50, 100, $userName . ', ' . $periodLabel, 24, false);
    $pdf->text(50, 130, $userEmail, 12, false);

    $rows = hours_pdf_collect_rows($data, $firstDay, $lastDay);
    $y = 160;
    $rowHeight = 25;
    $pageWidth = 595.28;
    $xDate = 50;
    $xHours = 250;
    $xKm = 400;

    $pdf->setTextColor($kvt[0], $kvt[1], $kvt[2]);
    $pdf->text($xDate, $y, 'Datum', 12, true);
    $pdf->text($xHours, $y, 'Gewerkte uren', 12, true);
    $pdf->text($xKm, $y, 'Gereden Kilometers', 12, true);
    $y += $rowHeight;

    $shade = false;
    $totalKm = 0.0;
    $extraSeconds = 0;

    foreach ($rows as $row) {
        $date = $row['date'];
        $day = $row['day'];

        if ($shade) {
            $pdf->setFillColor(211, 211, 211);
            $pdf->rect($xDate, $y - 4, $pageWidth - 100, $rowHeight, 'F');
        }

        $dayNumber = hours_weekday_index($date);
        $contract = hours_get_work_hours_seconds($data, $dayNumber);
        if (empty($day['isHoliday'])) {
            $worked = hours_parse_timespan((string) $day['WorkedTime']);
            $extraSeconds += ($worked - $contract);
        }

        $hoursLabel = !empty($day['isHoliday'])
            ? 'Vakantiedag'
            : (!empty($day['isSickDay']) ? 'Ziek' : (string) $day['WorkedString']);
        $dayKm = hours_day_driven_km($day, $data);
        $kmLabel = (!empty($day['isHoliday']) || !empty($day['isSickDay']))
            ? 'N.v.t.'
            : hours_format_km_nl($dayKm);

        if (empty($day['isHoliday']) && empty($day['isSickDay'])) {
            $totalKm += $dayKm;
        }

        $pdf->setTextColor($kvt[0], $kvt[1], $kvt[2]);
        $pdf->text($xDate, $y, janus_nl_date_pdf_row($date), 12, false);
        $pdf->text($xHours, $y, $hoursLabel, 12, false);
        $pdf->text($xKm, $y, $kmLabel, 12, false);

        $y += $rowHeight;
        $shade = !$shade;
    }

    $y = 785;
    $pdf->setTextColor($kvt[0], $kvt[1], $kvt[2]);
    if ($extraSeconds > 0) {
        $h = (int) floor($extraSeconds / 3600);
        $m = (int) floor(($extraSeconds % 3600) / 60);
        $minuteLabel = $m === 1 ? 'minuut' : 'minuten';
        $pdf->text(50, $y, sprintf('Overgewerkt: %d uur, %d %s', $h, $m, $minuteLabel), 12, true);
        $y += $rowHeight;
    }
    $pdf->text(50, $y, 'Totaal kilometers gereden: ' . number_format($totalKm, 1, ',', ''), 12, true);

    $days = [['Ma', 'Di', 'Wo', 'Do'], ['Vr', 'Za', 'Zo', '']];
    $isActive = [
        [
            hours_parse_timespan((string) ($data['MondayHours'] ?? '00:00:00')) > 0,
            hours_parse_timespan((string) ($data['TuesdayHours'] ?? '00:00:00')) > 0,
            hours_parse_timespan((string) ($data['WednesdayHours'] ?? '00:00:00')) > 0,
            hours_parse_timespan((string) ($data['ThursdayHours'] ?? '00:00:00')) > 0,
        ],
        [
            hours_parse_timespan((string) ($data['FridayHours'] ?? '00:00:00')) > 0,
            hours_parse_timespan((string) ($data['SaturdayHours'] ?? '00:00:00')) > 0,
            hours_parse_timespan((string) ($data['SundayHours'] ?? '00:00:00')) > 0,
            false,
        ],
    ];

    $squareW = 20.0;
    $squareH = 15.0;
    $spacing = 5.0;
    $cols = 4;
    $rowsN = 2;
    $gridW = $cols * $squareW + ($cols - 1) * $spacing;
    $startX = $pageWidth - $gridW - 50;
    $startY = 792.0;

    $pdf->setTextColor($kvt[0], $kvt[1], $kvt[2]);
    $pdf->text($startX - 110, $startY + 8, 'Vaste Werkdagen:', 12, false);

    for ($r = 0; $r < $rowsN; $r++) {
        for ($c = 0; $c < $cols; $c++) {
            if ($days[$r][$c] === '') {
                continue;
            }
            $sx = $startX + $c * ($squareW + $spacing);
            $sy = $startY + $r * ($squareH + $spacing);
            if ($isActive[$r][$c]) {
                $pdf->setFillColor(0, 153, 204);
            } else {
                $pdf->setFillColor(211, 211, 211);
            }
            $pdf->rect($sx, $sy, $squareW, $squareH, 'F');
            if ($isActive[$r][$c]) {
                $pdf->setTextColor(255, 255, 255);
            } else {
                $pdf->setTextColor(128, 128, 128);
            }
            $pdf->text($sx + 3, $sy + 11, $days[$r][$c], 8, false);
        }
    }

    return $pdf->output();
}
