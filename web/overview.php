<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/localization.php';
require_once __DIR__ . '/hours_data.php';

function overview_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$currentEmail = hours_current_user_email();
if ($currentEmail === '') {
    http_response_code(403);
    echo 'Geen gebruiker in sessie.';
    exit;
}
auth_require_page_access('overzicht');

$persons = [];
foreach (hours_list_users_with_data() as $email) {
    if (janus_is_light_mode($email)) {
        continue;
    }
    $data = hours_load_existing($email);
    if ($data === null) {
        continue;
    }
    $name = hours_resolve_user_name($data, $email);
    $data['UserEmail'] = $email;
    if (trim((string) ($data['UserName'] ?? '')) === '') {
        $data['UserName'] = $name;
    }

    $persons[] = [
        'id' => $email,
        'label' => $name !== '' ? $name : $email,
        'data' => $data,
    ];
}

usort($persons, static function (array $a, array $b): int {
    return strcasecmp((string) $a['label'], (string) $b['label']) ?: strcasecmp((string) $a['id'], (string) $b['id']);
});

$personsJson = json_encode($persons, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($personsJson === false) {
    $personsJson = '[]';
}

$canAccessHourTracker = auth_can_access_page('urentracker');
$canAccessOfficeDays = auth_can_access_page('kantoordagen');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Overzicht - Janus</title>
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
    <link rel="manifest" href="site.webmanifest">
    <style>
        :root {
            color-scheme: light;
            --bg: #eef3f8;
            --panel: rgba(255, 255, 255, 0.9);
            --panel-strong: #ffffff;
            --text: #163047;
            --muted: #60758a;
            --accent: #1f6fb2;
            --accent-soft: #d9ecff;
            --accent-strong: #0c4f84;
            --success: #167d67;
            --danger: #b44d4d;
            --shadow: 0 18px 40px rgba(21, 54, 84, 0.12);
            --hour-width: 56px;
            --timeline-width: calc(14 * var(--hour-width));
            --bar-start: #4fb3ff;
            --bar-end: #1168aa;
            --future-text: #91a0af;
        }
        * { box-sizing: border-box; }
        html, body {
            height: 100%;
            margin: 0;
            font-family: "Segoe UI", "Aptos", sans-serif;
            background:
                radial-gradient(circle at top left, rgba(79, 179, 255, 0.24), transparent 32%),
                radial-gradient(circle at top right, rgba(12, 79, 132, 0.12), transparent 28%),
                linear-gradient(180deg, #f5f9fd 0%, #edf3f8 100%);
            color: var(--text);
        }
        body { display: flex; min-height: 100vh; }
        .app-shell {
            display: flex;
            flex-direction: column;
            width: 100%;
            min-height: 100vh;
            padding: 18px;
            gap: 14px;
        }
        .topbar, .person-header, .weeks-panel {
            background: var(--panel);
            border: 1px solid rgba(170, 184, 203, 0.4);
            border-radius: 18px;
            box-shadow: var(--shadow);
            backdrop-filter: blur(10px);
        }
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px;
            flex-wrap: wrap;
        }
        .brand-row { display: flex; align-items: center; gap: 10px; }
        .brand-row img { height: 36px; width: auto; }
        .brand {
            font-size: 1rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: var(--accent-strong);
            padding: 0 10px 0 0;
        }
        .tabs { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .person-select {
            min-width: min(280px, 100%);
            padding: 10px 14px;
            border-radius: 999px;
            border: 1px solid rgba(31, 111, 178, 0.22);
            background: linear-gradient(180deg, #ffffff 0%, #eef7ff 100%);
            color: var(--accent-strong);
            font: inherit;
            font-weight: 600;
        }
        .topbar-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .nav-link, .nav-button {
            border: 1px solid rgba(31, 111, 178, 0.18);
            background: linear-gradient(180deg, #ffffff 0%, #eef7ff 100%);
            color: var(--accent-strong);
            border-radius: 999px;
            padding: 10px 16px;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
            transition: transform 140ms ease, box-shadow 140ms ease, border-color 140ms ease;
            text-decoration: none;
        }
        .nav-link:hover, .nav-button:hover,
        .nav-link:focus-visible, .nav-button:focus-visible {
            transform: translateY(-1px);
            border-color: rgba(31, 111, 178, 0.45);
            box-shadow: 0 10px 18px rgba(31, 111, 178, 0.12);
            outline: none;
        }
        .status-text { color: var(--muted); font-size: 0.95rem; padding: 0 8px; }
        .person-header {
            padding: 18px 22px;
            display: grid;
            grid-template-columns: repeat(4, minmax(180px, 1fr));
            gap: 16px;
            align-items: start;
        }
        .info-card {
            background: rgba(255, 255, 255, 0.85);
            border: 1px solid rgba(210, 219, 231, 0.8);
            border-radius: 14px;
            padding: 14px 16px;
            min-height: 100%;
        }
        .info-label {
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--muted);
            margin-bottom: 6px;
        }
        .info-value {
            font-size: 1.08rem;
            font-weight: 700;
            line-height: 1.35;
            word-break: break-word;
        }
        .weekday-badges { display: flex; gap: 8px; flex-wrap: wrap; }
        .weekday-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 42px;
            height: 34px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 0.92rem;
            border: 1px solid transparent;
        }
        .weekday-badge.active { background: var(--accent-soft); color: var(--accent-strong); border-color: rgba(31,111,178,0.18); }
        .weekday-badge.inactive { background: #e3e8ef; color: #78889a; border-color: rgba(120,136,154,0.18); }
        .weeks-panel {
            display: flex;
            flex-direction: column;
            min-height: 0;
            flex: 1;
            overflow: hidden;
        }
        .week-nav {
            display: flex;
            justify-content: center;
            gap: 10px;
            padding: 14px 16px;
            border-bottom: 1px solid rgba(210, 219, 231, 0.7);
            background: rgba(255, 255, 255, 0.6);
        }
        .week-nav.bottom { border-top: 1px solid rgba(210, 219, 231, 0.7); border-bottom: none; }
        .nav-button {
            color: #ffffff;
            background: linear-gradient(180deg, #2f91d5 0%, #0c4f84 100%);
            box-shadow: 0 10px 18px rgba(12, 79, 132, 0.18);
        }
        .nav-button:disabled { cursor: not-allowed; opacity: 0.45; box-shadow: none; }
        .weeks-scroll { overflow: hidden; position: relative; flex: 1; min-height: 0; }
        .carousel-strip { display: flex; flex-direction: column; padding: 18px; gap: 18px; will-change: transform; }
        .week-card {
            background: var(--panel-strong);
            border: 1px solid rgba(170, 184, 203, 0.38);
            border-radius: 18px;
            padding: 16px;
            box-shadow: 0 12px 26px rgba(22, 48, 71, 0.08);
        }
        .week-card.active-week { outline: 2px solid rgba(31, 111, 178, 0.2); }
        .week-kicker {
            display: inline-flex;
            align-items: center;
            padding: 6px 10px;
            margin-bottom: 10px;
            border-radius: 999px;
            background: #e8f3ff;
            color: var(--accent-strong);
            font-size: 0.82rem;
            font-weight: 800;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }
        .week-title { font-size: 1.08rem; font-weight: 700; margin-bottom: 12px; color: var(--accent-strong); }
        .timeline-header, .day-row {
            display: grid;
            grid-template-columns: 152px minmax(780px, 1fr) 164px 116px;
            gap: 14px;
            align-items: center;
        }
        .timeline-header { padding: 8px 0 12px; font-size: 0.84rem; color: var(--muted); font-weight: 700; }
        .hours-scale, .timeline-track { width: var(--timeline-width); position: relative; }
        .hours-scale { display: grid; grid-template-columns: repeat(14, var(--hour-width)); }
        .hours-scale span { text-align: left; transform: translateX(-0.5ch); }
        .day-row { padding: 10px 0; border-top: 1px solid rgba(210, 219, 231, 0.65); }
        .day-row:first-of-type { border-top: none; }
        .day-row.future { color: var(--future-text); }
        .day-row.future .timeline-track, .day-row.future .meta-box, .day-row.future .delta-box { opacity: 0.72; }
        .day-label { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .day-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: #eef7ff;
            border: 1px solid rgba(31, 111, 178, 0.14);
            font-size: 1.2rem;
            flex: 0 0 auto;
        }
        .day-text { min-width: 0; }
        .day-name { font-weight: 700; color: inherit; }
        .day-date, .day-note { color: var(--muted); font-size: 0.9rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .day-row.future .day-date, .day-row.future .day-note { color: inherit; }
        .timeline-shell { overflow-x: auto; padding-bottom: 4px; }
        .timeline-track {
            height: 48px;
            border: 1px solid rgba(170, 184, 203, 0.55);
            border-radius: 14px;
            background:
                repeating-linear-gradient(90deg, rgba(31, 111, 178, 0.11) 0, rgba(31, 111, 178, 0.11) 1px, transparent 1px, transparent var(--hour-width)),
                linear-gradient(180deg, #fbfdff 0%, #eef5fb 100%);
            overflow: hidden;
        }
        .timeline-track.future-track {
            background:
                repeating-linear-gradient(90deg, rgba(145, 160, 175, 0.16) 0, rgba(145, 160, 175, 0.16) 1px, transparent 1px, transparent var(--hour-width)),
                linear-gradient(180deg, #f6f7f8 0%, #eceff2 100%);
        }
        .work-bar {
            position: absolute;
            top: 7px;
            height: 32px;
            border-radius: 10px;
            background: linear-gradient(90deg, var(--bar-start) 0%, var(--bar-end) 100%);
            color: #ffffff;
            display: flex;
            align-items: center;
            padding: 0 10px;
            font-size: 0.9rem;
            font-weight: 700;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
            box-shadow: 0 8px 16px rgba(17, 104, 170, 0.2);
        }
        .work-bar.sick-bar { background: linear-gradient(90deg, #e57373 0%, #b71c1c 100%); box-shadow: 0 8px 16px rgba(183, 28, 28, 0.2); }
        .work-bar.vacation-bar { background: linear-gradient(90deg, #66bb6a 0%, #1b5e20 100%); box-shadow: 0 8px 16px rgba(27, 94, 32, 0.2); }
        .empty-label {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            padding-left: 12px;
            color: var(--muted);
            font-size: 0.9rem;
            font-weight: 600;
        }
        .meta-box, .delta-box {
            min-height: 48px;
            border-radius: 14px;
            border: 1px solid rgba(210, 219, 231, 0.9);
            background: rgba(247, 250, 253, 0.95);
            padding: 8px 12px;
            display: flex;
            align-items: center;
        }
        .meta-box { gap: 10px; }
        .break-emoji { font-size: 1.4rem; flex: 0 0 auto; }
        .meta-lines { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
        .meta-main, .meta-sub, .delta-caption { font-size: 0.9rem; }
        .meta-main, .delta-main { font-weight: 700; }
        .meta-sub, .delta-caption { color: var(--muted); }
        .delta-box { flex-direction: column; align-items: flex-start; justify-content: center; gap: 2px; }
        .delta-main.positive { color: var(--success); }
        .delta-main.negative { color: var(--danger); }
        .delta-main.neutral { color: var(--text); }
        .empty-state { padding: 28px; color: var(--muted); text-align: center; }
        @media (max-width: 1200px) {
            .person-header { grid-template-columns: repeat(2, minmax(180px, 1fr)); }
        }
        @media (max-width: 960px) {
            .app-shell { padding: 12px; }
            .person-header { grid-template-columns: 1fr; }
            .timeline-header, .day-row { grid-template-columns: 1fr; gap: 10px; }
            .timeline-header { display: none; }
            .timeline-shell { max-width: 100%; }
            .meta-box, .delta-box { min-height: 0; }
        }
    </style>
</head>
<body>
    <div class="app-shell">
        <section class="topbar">
            <div class="brand-row">
                <img src="logo-website.png" alt="KVT">
                <div class="brand">Overzicht</div>
            </div>
            <div class="tabs" id="person-tabs">
                <select id="personSelect" class="person-select" aria-label="Persoon"></select>
            </div>
            <div class="topbar-actions">
                <?php if ($canAccessHourTracker): ?>
                    <a class="nav-link" href="index.php">Urentracker</a>
                <?php endif; ?>
                <?php if ($canAccessOfficeDays): ?>
                    <a class="nav-link" href="office_days.php">Kantoordagen</a>
                <?php endif; ?>
                <a class="nav-link" href="settings.php">Instellingen</a>
                <div class="status-text" id="status-text">Gegevens laden...</div>
            </div>
        </section>

        <section class="person-header" id="person-header"></section>

        <section class="weeks-panel">
            <div class="week-nav">
                <button class="nav-button" id="newer-top" type="button">Nieuwere week</button>
                <button class="nav-button" id="older-top" type="button">Oudere week</button>
            </div>
            <div class="weeks-scroll" id="weeks-scroll"></div>
            <div class="week-nav bottom">
                <button class="nav-button" id="newer-bottom" type="button">Nieuwere week</button>
                <button class="nav-button" id="older-bottom" type="button">Oudere week</button>
            </div>
        </section>
    </div>

    <script>
        const personSources = <?= $personsJson ?>;
        const weekdayConfig = [
            { key: "MondayHours", short: "Ma" },
            { key: "TuesdayHours", short: "Di" },
            { key: "WednesdayHours", short: "Wo" },
            { key: "ThursdayHours", short: "Do" },
            { key: "FridayHours", short: "Vr" },
            { key: "SaturdayHours", short: "Za" },
            { key: "SundayHours", short: "Zo" }
        ];
        const weekdayNames = ["Maandag", "Dinsdag", "Woensdag", "Donderdag", "Vrijdag", "Zaterdag", "Zondag"];
        const dayKeysByIndex = ["MondayHours", "TuesdayHours", "WednesdayHours", "ThursdayHours", "FridayHours", "SaturdayHours", "SundayHours"];
        const workdayStartMinutes = 6 * 60;
        const workdayEndMinutes = 20 * 60;
        const timelineMinutes = workdayEndMinutes - workdayStartMinutes;
        const MONTH_EXTRA_LOOKBACK_MONTHS = 2;
        const monthAbbrevs = ["jan", "feb", "mrt", "apr", "mei", "jun", "jul", "aug", "sep", "okt", "nov", "dec"];
        const CAROUSEL_GAP = 18;

        let activePersonId = null;
        let activePersonData = null;
        let weekStarts = [];
        let focusedWeekIndex = 0;
        let isAnimating = false;

        const personSelect = document.getElementById("personSelect");
        const statusElement = document.getElementById("status-text");
        const headerElement = document.getElementById("person-header");
        const weeksScrollElement = document.getElementById("weeks-scroll");
        const newerTopButton = document.getElementById("newer-top");
        const olderTopButton = document.getElementById("older-top");
        const newerBottomButton = document.getElementById("newer-bottom");
        const olderBottomButton = document.getElementById("older-bottom");

        function initializeTabs() {
            personSelect.innerHTML = "";
            if (personSources.length === 0) {
                personSelect.disabled = true;
                const option = document.createElement("option");
                option.value = "";
                option.textContent = "Geen personen met data";
                personSelect.appendChild(option);
                return;
            }

            personSelect.disabled = false;
            personSources.forEach((person) => {
                const option = document.createElement("option");
                option.value = person.id;
                option.textContent = person.label || person.id;
                personSelect.appendChild(option);
            });
        }

        function loadPerson(personId) {
            const selected = personSources.find((entry) => entry.id === personId);
            if (!selected) {
                statusElement.textContent = "Onbekende persoon.";
                return;
            }

            personSelect.value = personId;
            statusElement.textContent = "Gegevens laden...";
            headerElement.innerHTML = "";
            weeksScrollElement.innerHTML = "<div class=\"empty-state\">Gegevens worden geladen...</div>";

            activePersonId = personId;
            activePersonData = selected.data || null;
            weekStarts = buildWeekStarts((activePersonData && activePersonData.SavedDays) || {});
            focusedWeekIndex = 0;
            isAnimating = false;

            renderHeader(activePersonData);
            renderWeeks();
            statusElement.textContent = (activePersonData.UserName || selected.label || selected.id) + " geladen";
        }

        function renderHeader(personData) {
            const singleTrip = Number(personData.KilometerHomeWork || 0);
            const roundTrip = singleTrip * 2;
            headerElement.innerHTML = [
                createInfoCard("Naam", escapeHtml(personData.UserName || "Onbekend")),
                createInfoCard("E-mail", escapeHtml(personData.UserEmail || "-")),
                createInfoCard("Werkdagen", renderWeekdayBadges(personData)),
                createInfoCard("Woon-werk afstand", "<div>" + formatDecimal(singleTrip) + " km enkele rit</div><div>" + formatDecimal(roundTrip) + " km retour</div>")
            ].join("");
        }

        function createInfoCard(label, valueHtml) {
            return "<div class=\"info-card\"><div class=\"info-label\">" + label + "</div><div class=\"info-value\">" + valueHtml + "</div></div>";
        }

        function renderWeekdayBadges(personData) {
            return "<div class=\"weekday-badges\">" + weekdayConfig.map((day) => {
                const minutes = parseTimeToMinutes(personData[day.key]);
                const active = minutes > 0;
                return "<span class=\"weekday-badge " + (active ? "active" : "inactive") + "\">" + day.short + "</span>";
            }).join("") + "</div>";
        }

        function buildWeekStarts(savedDays) {
            const savedDates = Object.keys(savedDays)
                .map(parseSavedDate)
                .filter(Boolean)
                .sort((left, right) => left - right);

            const today = startOfDay(new Date());
            const newestWeekStart = startOfWeek(today);
            const oldestRelevantDate = savedDates.length > 0 ? savedDates[0] : today;
            const oldestWeekStart = startOfWeek(oldestRelevantDate);
            const weeks = [];

            for (let cursor = new Date(newestWeekStart); cursor >= oldestWeekStart; cursor = addDays(cursor, -7)) {
                weeks.push(new Date(cursor));
            }

            return weeks;
        }

        function getCarouselStrip() {
            let strip = document.getElementById("carousel-strip");
            if (!strip) {
                strip = document.createElement("div");
                strip.id = "carousel-strip";
                strip.className = "carousel-strip";
                weeksScrollElement.innerHTML = "";
                weeksScrollElement.appendChild(strip);
            }
            return strip;
        }

        function snapToActive(strip) {
            const cards = Array.from(strip.querySelectorAll(".week-card"));
            const activeIdx = cards.findIndex((c) => c.dataset.role === "active");
            if (activeIdx <= 0) {
                strip.style.transform = "translateY(0)";
                return;
            }
            let offset = 0;
            for (let i = 0; i < activeIdx; i++) {
                offset += cards[i].offsetHeight + CAROUSEL_GAP;
            }
            strip.style.transform = "translateY(-" + offset + "px)";
        }

        function renderWeeks() {
            if (!activePersonData || weekStarts.length === 0) {
                weeksScrollElement.innerHTML = "<div class=\"empty-state\">" + (!activePersonData ? "Geen persoon geladen." : "Geen weken beschikbaar.") + "</div>";
                updateNavigation();
                return;
            }

            const strip = getCarouselStrip();
            strip.style.transition = "none";
            strip.style.transform = "translateY(0)";

            const savedDays = activePersonData.SavedDays || {};
            const visibleWeeks = getVisibleWeeks();

            strip.innerHTML = visibleWeeks.map(({ weekStart, index, role }) => {
                const weekEnd = addDays(weekStart, 6);
                const days = Array.from({ length: 7 }, (_, offset) => addDays(weekStart, offset)).reverse();
                const isActive = role === "active";
                const kickerLabel = role === "newer" ? "Nieuwere week" : role === "older" ? "Oudere week" : "Geselecteerde week";

                return [
                    "<section class=\"week-card" + (isActive ? " active-week" : "") + "\" data-week-index=\"" + index + "\" data-role=\"" + role + "\">",
                    "<div class=\"week-kicker\">" + kickerLabel + "</div>",
                    "<div class=\"week-title\">Week van " + formatDateLabel(weekStart) + " t/m " + formatDateLabel(weekEnd) + "</div>",
                    renderTimelineHeader(weekStart),
                    days.map((date) => renderDayRow(date, savedDays)).join(""),
                    "</section>"
                ].join("");
            }).join("");

            requestAnimationFrame(() => {
                snapToActive(strip);
                updateNavigation();
            });
        }

        function getVisibleWeeks() {
            const result = [];
            if (focusedWeekIndex > 0) {
                result.push({ index: focusedWeekIndex - 1, weekStart: weekStarts[focusedWeekIndex - 1], role: "newer" });
            }
            result.push({ index: focusedWeekIndex, weekStart: weekStarts[focusedWeekIndex], role: "active" });
            if (focusedWeekIndex < weekStarts.length - 1) {
                result.push({ index: focusedWeekIndex + 1, weekStart: weekStarts[focusedWeekIndex + 1], role: "older" });
            }
            return result;
        }

        function renderTimelineHeader(weekStart) {
            const hourLabels = [];
            for (let hour = 6; hour < 20; hour += 1) {
                hourLabels.push("<span>" + pad2(hour) + ":00</span>");
            }
            const extraMinutes = getMonthExtraMinutesForDate(weekStart);
            const extraLabel = formatExtraMinutesForHeader(extraMinutes);

            return [
                "<div class=\"timeline-header\">",
                "<div>Dag</div>",
                "<div class=\"timeline-shell\"><div class=\"hours-scale\">" + hourLabels.join("") + "</div></div>",
                "<div>Pauze / km</div>",
                "<div>Saldo (" + extraLabel + ")</div>",
                "</div>"
            ].join("");
        }

        function getMonthExtraMinutesForDate(date) {
            const monthExtraTicks = (activePersonData && activePersonData.MonthExtraTicks) ? activePersonData.MonthExtraTicks : {};
            let totalTicks = 0;
            for (let offset = 0; offset < MONTH_EXTRA_LOOKBACK_MONTHS; offset += 1) {
                const refDate = new Date(date.getFullYear(), date.getMonth() - offset, 1);
                const key = pad2(refDate.getMonth() + 1) + "-" + refDate.getFullYear();
                totalTicks += Number(monthExtraTicks[key] || 0);
            }
            return Math.trunc(totalTicks / 10000000 / 60);
        }

        function formatExtraMinutesForHeader(minutes) {
            const sign = minutes >= 0 ? "+" : "-";
            const absolute = Math.abs(minutes);
            const hours = Math.floor(absolute / 60);
            const restMinutes = absolute % 60;
            return sign + pad2(hours) + ":" + pad2(restMinutes);
        }

        function renderDayRow(date, savedDays) {
            const key = formatSavedDate(date);
            const entry = savedDays[key] || null;
            const today = startOfDay(new Date());
            const isFuture = date > today;
            const weekdayIndex = getMondayFirstIndex(date);
            const scheduledMinutes = parseTimeToMinutes(activePersonData[dayKeysByIndex[weekdayIndex]]);

            const isSick = !!(entry && entry.isSickDay);
            const isVacation = !!(entry && entry.isHoliday);
            const isExcused = isSick || isVacation;
            const isOffDay = scheduledMinutes === 0;

            const startMinutes = entry ? parseTimeToMinutes(entry.StartTime) : null;
            const endMinutes = entry ? parseTimeToMinutes(entry.EndTime) : null;
            const workedMinutes = isExcused ? 0 : (entry ? getWorkedMinutes(entry, startMinutes, endMinutes) : 0);
            const breakMinutes = isExcused ? 0 : (entry ? Number(entry.BreakMinutes || 0) : 0);
            const kilometers = entry ? Number(entry.Kilometers || 0) : 0;
            const deltaMinutes = isExcused ? null : (workedMinutes - scheduledMinutes);
            const icon = entry ? (entry.HomeWorkDriven ? "🏢" : "🏠") : "-";
            const trackClass = isFuture ? "timeline-track future-track" : "timeline-track";

            return [
                "<div class=\"day-row" + (isFuture ? " future" : "") + "\">",
                "<div class=\"day-label\">",
                "<div class=\"day-icon\">" + icon + "</div>",
                "<div class=\"day-text\">",
                "<div class=\"day-name\">" + weekdayNames[weekdayIndex] + "</div>",
                "<div class=\"day-date\">" + formatDateLabel(date) + "</div>",
                "<div class=\"day-note\">" + renderDayNote(entry, isFuture, isOffDay, weekdayIndex) + "</div>",
                "</div>",
                "</div>",
                "<div class=\"timeline-shell\">",
                renderTimelineTrack(trackClass, entry, startMinutes, endMinutes, workedMinutes, isSick, isVacation, isOffDay, weekdayIndex),
                "</div>",
                renderMetaBox(breakMinutes, kilometers, isSick, isVacation, isOffDay),
                renderDeltaBox(deltaMinutes, scheduledMinutes),
                "</div>"
            ].join("");
        }

        function renderDayNote(entry, isFuture, isOffDay, weekdayIndex) {
            if (isFuture) {
                return isOffDay ? "Werkt niet op " + weekdayNames[weekdayIndex] : "Nog niet geweest";
            }
            if (!entry) {
                return isOffDay ? "Werkt niet op " + weekdayNames[weekdayIndex] : "Geen gegevens";
            }
            if (entry.isHoliday) return "Vakantie";
            if (entry.isSickDay) return "Ziek";
            if (isOffDay) return "Werkt niet op " + weekdayNames[weekdayIndex];
            return escapeHtml(entry.WorkedString || "Werkdag");
        }

        function renderTimelineTrack(trackClass, entry, startMinutes, endMinutes, workedMinutes, isSick, isVacation, isOffDay, weekdayIndex) {
            const parts = ["<div class=\"" + trackClass + "\">"];
            if (isSick) {
                parts.push("<div class=\"work-bar sick-bar\" style=\"left:0%;width:100%;\">Ziek</div>");
            } else if (isVacation) {
                parts.push("<div class=\"work-bar vacation-bar\" style=\"left:0%;width:100%;\">Vakantie</div>");
            } else if (!entry || workedMinutes <= 0 || startMinutes === null || endMinutes === null || endMinutes <= startMinutes) {
                const label = isOffDay
                    ? "Werkt niet op " + weekdayNames[weekdayIndex]
                    : (entry ? escapeHtml(entry.WorkedString || "0 uur, 0 minuten") : "Geen registratie");
                parts.push("<div class=\"empty-label\">" + label + "</div>");
            } else {
                const barMetrics = calculateBarMetrics(startMinutes, endMinutes);
                parts.push("<div class=\"work-bar\" style=\"left:" + barMetrics.leftPercent + "%;width:" + barMetrics.widthPercent + "%;\">" + escapeHtml(entry.WorkedString || formatMinutesAsDuration(workedMinutes)) + "</div>");
            }
            parts.push("</div>");
            return parts.join("");
        }

        function renderMetaBox(breakMinutes, kilometers, isSick, isVacation, isOffDay) {
            const isBlank = isSick || isVacation || isOffDay;
            const emoji = isSick ? "🤒" : isVacation ? "🌴" : isOffDay ? "😎" : getBreakEmoji(breakMinutes);
            const pauseLabel = isBlank ? "" : breakMinutes + " min pauze";
            const kmLabel = isBlank ? "" : formatDecimal(kilometers) + " kilometer";
            return [
                "<div class=\"meta-box\">",
                "<div class=\"break-emoji\">" + emoji + "</div>",
                "<div class=\"meta-lines\">",
                "<div class=\"meta-main\">" + pauseLabel + "</div>",
                "<div class=\"meta-sub\">" + kmLabel + "</div>",
                "</div>",
                "</div>"
            ].join("");
        }

        function renderDeltaBox(deltaMinutes, scheduledMinutes) {
            if (deltaMinutes === null) {
                return "<div class=\"delta-box\"><div class=\"delta-main neutral\">—</div><div class=\"delta-caption\">niet van toepassing</div></div>";
            }
            const signClass = deltaMinutes > 0 ? "positive" : deltaMinutes < 0 ? "negative" : "neutral";
            const caption = scheduledMinutes > 0 ? "t.o.v. planning" : "geen planning";
            return "<div class=\"delta-box\"><div class=\"delta-main " + signClass + "\">" + formatSignedMinutes(deltaMinutes) + "</div><div class=\"delta-caption\">" + caption + "</div></div>";
        }

        function calculateBarMetrics(startMinutes, endMinutes) {
            const clampedStart = Math.max(workdayStartMinutes, Math.min(workdayEndMinutes, startMinutes));
            const clampedEnd = Math.max(workdayStartMinutes, Math.min(workdayEndMinutes, endMinutes));
            const leftPercent = ((clampedStart - workdayStartMinutes) / timelineMinutes) * 100;
            const widthPercent = Math.max(((clampedEnd - clampedStart) / timelineMinutes) * 100, 1.2);
            return { leftPercent, widthPercent };
        }

        function getWorkedMinutes(entry, startMinutes, endMinutes) {
            if (startMinutes !== null && endMinutes !== null && endMinutes > startMinutes) {
                return Math.max(endMinutes - startMinutes - Number(entry.BreakMinutes || 0), 0);
            }
            return parseTimeToMinutes(entry.WorkedTime);
        }

        function getBreakEmoji(breakMinutes) {
            if (breakMinutes < 1) return "😡";
            if (breakMinutes < 5) return "🥵";
            if (breakMinutes < 10) return "😰";
            if (breakMinutes < 15) return "😥";
            if (breakMinutes < 20) return "😐";
            if (breakMinutes < 28) return "🙂";
            if (breakMinutes < 33) return "😊";
            if (breakMinutes < 35) return "😌";
            if (breakMinutes < 40) return "🥱";
            if (breakMinutes < 45) return "😪";
            return "😴";
        }

        function updateNavigation() {
            const hasWeeks = weekStarts.length > 0;
            const canGoNewer = hasWeeks && focusedWeekIndex > 0;
            const canGoOlder = hasWeeks && focusedWeekIndex < weekStarts.length - 1;
            newerTopButton.disabled = !canGoNewer;
            newerBottomButton.disabled = !canGoNewer;
            olderTopButton.disabled = !canGoOlder;
            olderBottomButton.disabled = !canGoOlder;
        }

        function focusWeek(nextIndex) {
            if (!activePersonData || isAnimating || nextIndex < 0 || nextIndex >= weekStarts.length) {
                return;
            }
            const strip = getCarouselStrip();
            const cards = Array.from(strip.querySelectorAll(".week-card"));
            const activeIdx = cards.findIndex((c) => c.dataset.role === "active");
            const direction = nextIndex > focusedWeekIndex ? 1 : -1;
            const targetIdx = activeIdx + direction;

            if (targetIdx < 0 || targetIdx >= cards.length) {
                focusedWeekIndex = nextIndex;
                renderWeeks();
                return;
            }

            isAnimating = true;
            let targetOffset = 0;
            for (let i = 0; i < targetIdx; i++) {
                targetOffset += cards[i].offsetHeight + CAROUSEL_GAP;
            }

            strip.style.transition = "transform 380ms cubic-bezier(0.4, 0, 0.2, 1)";
            strip.style.transform = "translateY(-" + targetOffset + "px)";
            strip.addEventListener("transitionend", () => {
                strip.style.transition = "none";
                isAnimating = false;
                focusedWeekIndex = nextIndex;
                renderWeeks();
            }, { once: true });
        }

        function parseSavedDate(value) {
            if (!value || typeof value !== "string") return null;
            const [day, month, year] = value.split("-").map(Number);
            if (!day || !month || !year) return null;
            return new Date(year, month - 1, day);
        }

        function formatSavedDate(date) {
            return pad2(date.getDate()) + "-" + pad2(date.getMonth() + 1) + "-" + date.getFullYear();
        }

        function formatDateLabel(date) {
            return date.getDate() + " " + monthAbbrevs[date.getMonth()] + " " + date.getFullYear();
        }

        function formatDecimal(value) {
            return Number(value || 0).toLocaleString("nl-NL", {
                minimumFractionDigits: Number.isInteger(Number(value || 0)) ? 0 : 1,
                maximumFractionDigits: 1
            });
        }

        function parseTimeToMinutes(value) {
            if (!value || typeof value !== "string") return 0;
            const [hours, minutes, seconds] = value.split(":").map(Number);
            if ([hours, minutes, seconds].some((part) => Number.isNaN(part))) return 0;
            return (hours * 60) + minutes + Math.floor((seconds || 0) / 60);
        }

        function formatSignedMinutes(totalMinutes) {
            const sign = totalMinutes > 0 ? "+" : totalMinutes < 0 ? "-" : "";
            const absolute = Math.abs(totalMinutes);
            const hours = Math.floor(absolute / 60);
            const minutes = absolute % 60;
            return sign + pad2(hours) + ":" + pad2(minutes);
        }

        function formatMinutesAsDuration(totalMinutes) {
            const hours = Math.floor(totalMinutes / 60);
            const minutes = totalMinutes % 60;
            return hours + " uur, " + minutes + " minuten";
        }

        function getMondayFirstIndex(date) {
            return (date.getDay() + 6) % 7;
        }

        function startOfWeek(date) {
            return addDays(startOfDay(date), -getMondayFirstIndex(date));
        }

        function startOfDay(date) {
            return new Date(date.getFullYear(), date.getMonth(), date.getDate());
        }

        function addDays(date, amount) {
            const copy = new Date(date);
            copy.setDate(copy.getDate() + amount);
            return copy;
        }

        function pad2(value) {
            return String(value).padStart(2, "0");
        }

        function escapeHtml(value) {
            return String(value)
                .replaceAll("&", "&amp;")
                .replaceAll("<", "&lt;")
                .replaceAll(">", "&gt;")
                .replaceAll('"', "&quot;")
                .replaceAll("'", "&#39;");
        }

        newerTopButton.addEventListener("click", () => focusWeek(focusedWeekIndex - 1));
        newerBottomButton.addEventListener("click", () => focusWeek(focusedWeekIndex - 1));
        olderTopButton.addEventListener("click", () => focusWeek(focusedWeekIndex + 1));
        olderBottomButton.addEventListener("click", () => focusWeek(focusedWeekIndex + 1));
        personSelect.addEventListener("change", () => {
            if (personSelect.value) {
                loadPerson(personSelect.value);
            }
        });

        initializeTabs();
        updateNavigation();
        if (personSources.length > 0) {
            statusElement.textContent = personSources.length + " persoon/personen met data";
            loadPerson(personSources[0].id);
        } else {
            weeksScrollElement.innerHTML = "<div class=\"empty-state\">Geen personen met data.</div>";
            statusElement.textContent = "Geen personen met data";
        }
    </script>
</body>
</html>
