<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/hours_data.php';

function office_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$email = hours_current_user_email();
if ($email === '') {
    http_response_code(403);
    echo 'Geen gebruiker in sessie.';
    exit;
}
auth_require_page_access('kantoordagen');

$today = new DateTimeImmutable('today');
$defaultStart = $today->modify('first day of this month');
$canAccessHourTracker = auth_can_access_page('urentracker');
$canAccessOverview = auth_can_access_page('overzicht');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kantoordagen - Janus</title>
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
    <link rel="manifest" href="site.webmanifest">
    <link rel="stylesheet" href="brand.css">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--kvt-page-bg); color: var(--kvt-text); }
        .wrap { max-width: 960px; margin: 0 auto; padding: 12px 14px 28px; }
        .topbar, .range-bar, .row-head, .user-actions { display: flex; gap: 8px; }
        .topbar, .range-bar { flex-wrap: wrap; align-items: center; justify-content: space-between; }
        .brand-row { display: flex; align-items: center; gap: 10px; }
        .brand-row img { height: 36px; width: auto; }
        .brand-row h1 { margin: 0; font-size: 1.35rem; color: var(--kvt-perkins-blue); }
        .btn, button {
            appearance: none; border: 1px solid var(--kvt-line); background: #fff; color: var(--kvt-text);
            border-radius: 4px; padding: 8px 10px; font-size: 0.9rem; cursor: pointer; text-decoration: none;
        }
        .btn-primary { background: var(--kvt-main-blue); border-color: var(--kvt-main-blue); color: #fff; }
        .panel { border: 1px solid var(--kvt-line); border-radius: 6px; padding: 12px; background: #fff; margin-top: 12px; }
        .range-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; width: 100%; }
        .person-row { margin-top: 12px; }
        select.person-select {
            width: 100%;
            padding: 8px;
            border: 1px solid var(--kvt-line);
            border-radius: 4px;
            font-size: 1rem;
            background: #fff;
        }
        label { display: block; font-size: 0.82rem; color: var(--kvt-muted); margin-bottom: 4px; }
        input[type="date"] { width: 100%; padding: 8px; border: 1px solid var(--kvt-line); border-radius: 4px; font-size: 1rem; }
        .users { display: grid; gap: 10px; }
        .user-card { border: 1px solid var(--kvt-line); border-radius: 6px; padding: 12px; background: #fff; }
        .user-card.warn { animation: officeWarn 1s linear infinite alternate; border-color: #f59e0b; }
        .row-head { justify-content: space-between; align-items: flex-start; }
        .user-name { font-weight: 700; }
        .user-email, .muted { color: var(--kvt-muted); font-size: 0.85rem; }
        .office-total { font-size: 1.2rem; font-weight: 700; color: var(--kvt-perkins-blue); margin: 8px 0; }
        .warning { margin-top: 8px; padding: 8px 10px; border-radius: 4px; background: #fff3cd; color: #8a5800; font-size: 0.9rem; }
        .empty { color: var(--kvt-muted); text-align: center; padding: 18px; }
        .pdf-modal {
            position: fixed; inset: 0; background: rgba(0,0,0,.55); display: none; align-items: stretch;
            justify-content: center; z-index: 2000; padding: 10px;
        }
        .pdf-modal.open { display: flex; }
        .pdf-modal-dialog {
            width: min(1200px, 100%); background: #fff; border-radius: 8px; overflow: hidden;
            display: flex; flex-direction: column; box-shadow: 0 20px 40px rgba(0,0,0,.3);
        }
        .pdf-modal-bar {
            display: flex; align-items: center; justify-content: space-between; gap: 8px;
            padding: 8px; border-bottom: 1px solid var(--kvt-line); background: #f8fbff;
        }
        .pdf-modal-body { padding: 10px; overflow: auto; background: #e9edf2; min-height: 70vh; }
        .pdf-preview-content { margin: 0 auto; width: fit-content; max-width: 100%; background: #fff; }
        @keyframes officeWarn {
            from { background: #fff; }
            to { background: #fff0d8; }
        }
        @media (max-width: 520px) {
            .range-grid { grid-template-columns: 1fr; }
            .row-head, .user-actions { display: block; }
            .user-actions .btn { width: 100%; margin-top: 8px; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="topbar">
        <div class="brand-row">
            <img src="logo-website.png" alt="KVT">
            <h1 class="brand-display">Kantoordagen</h1>
        </div>
        <div class="user-actions">
            <?php if ($canAccessHourTracker): ?>
                <a class="btn" href="index.php">Urentracker</a>
            <?php endif; ?>
            <?php if ($canAccessOverview): ?>
                <a class="btn" href="overview.php">Overzicht</a>
            <?php endif; ?>
            <a class="btn" href="settings.php">Instellingen</a>
        </div>
    </div>

    <div class="panel">
        <div class="range-grid">
            <div>
                <label for="start">Startdatum</label>
                <input type="date" id="start" value="<?= office_h($defaultStart->format('Y-m-d')) ?>">
            </div>
            <div>
                <label for="end">Einddatum</label>
                <input type="date" id="end" value="<?= office_h($today->format('Y-m-d')) ?>">
            </div>
        </div>
        <div class="person-row">
            <label for="personSelect">Medewerker</label>
            <select id="personSelect" class="person-select" disabled>
                <option value="">Laden...</option>
            </select>
        </div>
        <div class="muted" id="rangeStatus" style="margin-top:10px;">Laden...</div>
    </div>

    <div class="users" id="users"></div>
</div>

<div class="pdf-modal" id="pdfModal" aria-hidden="true">
    <div class="pdf-modal-dialog">
        <div class="pdf-modal-bar">
            <strong>PDF</strong>
            <div style="display:flex;gap:8px;">
                <button type="button" class="btn btn-primary" id="pdfModalPrint">Print</button>
                <button type="button" class="btn" id="pdfModalClose">Sluiten</button>
            </div>
        </div>
        <div class="pdf-modal-body">
            <div id="pdfPreviewContent" class="pdf-preview-content"></div>
        </div>
    </div>
</div>

<script>
(function () {
    var start = document.getElementById('start');
    var end = document.getElementById('end');
    var users = document.getElementById('users');
    var personSelect = document.getElementById('personSelect');
    var rangeStatus = document.getElementById('rangeStatus');
    var pdfModal = document.getElementById('pdfModal');
    var pdfPreviewContent = document.getElementById('pdfPreviewContent');
    var pdfModalClose = document.getElementById('pdfModalClose');
    var pdfModalPrint = document.getElementById('pdfModalPrint');
    var reloadTimer = 0;
    var currentRows = [];
    var selectedEmail = '';

    function esc(value) {
        return String(value ?? '').replace(/[&<>"]/g, function (char) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[char];
        });
    }

    function closePdfModal() {
        pdfModal.classList.remove('open');
        pdfModal.setAttribute('aria-hidden', 'true');
        pdfPreviewContent.innerHTML = '';
    }

    function loadPreview(url) {
        pdfPreviewContent.innerHTML = '<div style="padding:20px;background:#fff">Laden...</div>';
        fetch(url, { cache: 'no-store' })
            .then(function (resp) { return resp.text(); })
            .then(function (html) {
                pdfPreviewContent.innerHTML = html;
                pdfModal.classList.add('open');
                pdfModal.setAttribute('aria-hidden', 'false');
            })
            .catch(function () {
                pdfPreviewContent.innerHTML = '<div style="padding:20px;background:#fff;color:#b42318">PDF laden mislukt.</div>';
                pdfModal.classList.add('open');
                pdfModal.setAttribute('aria-hidden', 'false');
            });
    }

    function fillPersonSelect(rows) {
        var previous = selectedEmail || personSelect.value;
        personSelect.innerHTML = '';
        if (!rows.length) {
            personSelect.disabled = true;
            var empty = document.createElement('option');
            empty.value = '';
            empty.textContent = 'Geen medewerkers met data';
            personSelect.appendChild(empty);
            selectedEmail = '';
            return;
        }

        personSelect.disabled = false;
        rows.forEach(function (row) {
            var option = document.createElement('option');
            option.value = row.email;
            option.textContent = row.name || row.email;
            personSelect.appendChild(option);
        });

        var stillExists = rows.some(function (row) { return row.email === previous; });
        selectedEmail = stillExists ? previous : rows[0].email;
        personSelect.value = selectedEmail;
    }

    function renderSelected() {
        if (!currentRows.length || !selectedEmail) {
            users.innerHTML = '<div class="panel empty">Geen gebruikersdata gevonden.</div>';
            return;
        }

        var row = currentRows.find(function (item) { return item.email === selectedEmail; }) || currentRows[0];
        selectedEmail = row.email;
        personSelect.value = selectedEmail;

        var warningHtml = row.warning
            ? '<div class="warning">' + esc(row.warning) + '</div>'
            : '';
        users.innerHTML = ''
            + '<div class="user-card' + (row.missingDays.length ? ' warn' : '') + '">'
            + '  <div class="row-head">'
            + '    <div>'
            + '      <div class="user-name">' + esc(row.name) + '</div>'
            + '      <div class="user-email">' + esc(row.email) + '</div>'
            + '    </div>'
            + '    <div class="user-actions">'
            + '      <button type="button" class="btn btn-primary" id="officePdfBtn">PDF</button>'
            + '    </div>'
            + '  </div>'
            + '  <div class="office-total">' + esc(row.officeDays) + ' kantoordagen</div>'
            + warningHtml
            + '</div>';

        document.getElementById('officePdfBtn').addEventListener('click', function () {
            var url = 'export_preview.php?fragment=1&mode=custom'
                + '&user=' + encodeURIComponent(row.email)
                + '&start=' + encodeURIComponent(start.value)
                + '&end=' + encodeURIComponent(end.value)
                + '&_ts=' + Date.now();
            loadPreview(url);
        });
    }

    function reload() {
        if (!start.value || !end.value) return;
        if (end.value < start.value) {
            end.value = start.value;
        }
        rangeStatus.textContent = 'Laden...';
        fetch('office_days_api.php?start=' + encodeURIComponent(start.value) + '&end=' + encodeURIComponent(end.value) + '&_ts=' + Date.now(), {
            cache: 'no-store'
        })
            .then(function (resp) { return resp.json(); })
            .then(function (payload) {
                if (!payload || !payload.ok) {
                    throw new Error('invalid');
                }
                var startLabel = payload.startLabel || payload.start;
                var endLabel = payload.endLabel || payload.end;
                rangeStatus.textContent = 'Periode: ' + startLabel + ' t/m ' + endLabel;
                currentRows = payload.rows || [];
                fillPersonSelect(currentRows);
                renderSelected();
            })
            .catch(function () {
                rangeStatus.textContent = 'Laden mislukt.';
                currentRows = [];
                fillPersonSelect([]);
                users.innerHTML = '<div class="panel empty">Laden mislukt.</div>';
            });
    }

    function scheduleReload() {
        clearTimeout(reloadTimer);
        reloadTimer = setTimeout(reload, 150);
    }

    start.addEventListener('input', scheduleReload);
    end.addEventListener('input', scheduleReload);
    start.addEventListener('change', scheduleReload);
    end.addEventListener('change', scheduleReload);
    personSelect.addEventListener('change', function () {
        selectedEmail = personSelect.value || '';
        renderSelected();
    });

    pdfModalClose.addEventListener('click', closePdfModal);
    pdfModalPrint.addEventListener('click', function () {
        if (!pdfPreviewContent.innerHTML.trim()) return;
        var printWin = window.open('', '_blank');
        if (!printWin) return;
        printWin.document.open();
        printWin.document.write('<!doctype html><html><head><meta charset="utf-8"><title>Janus PDF</title></head><body>' + pdfPreviewContent.innerHTML + '</body></html>');
        printWin.document.close();
        printWin.focus();
        setTimeout(function () { printWin.print(); }, 150);
    });
    pdfModal.addEventListener('click', function (e) {
        if (e.target === pdfModal) closePdfModal();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && pdfModal.classList.contains('open')) closePdfModal();
    });

    reload();
})();
</script>
</body>
</html>
