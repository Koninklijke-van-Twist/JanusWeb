<?php
/**
 * Light-mode urentracker: office-day calendar labeled "Kantoordagen".
 * Included from index.php after auth when janus_is_light_mode() is true.
 */

$userName = hours_current_user_name();
$needsSetup = janus_needs_light_setup($email);
$existing = hours_load_existing($email);
if ($existing !== null) {
    $data = $existing;
    $userName = hours_resolve_user_name($data, $email);
} else {
    $data = hours_default_save_data($userName, $email);
}
$canAccessOfficeDays = auth_can_access_page('kantoordagen');
$canAccessOverview = auth_can_access_page('overzicht');
$defaultOffice = hours_get_default_office_days($data);
$officeLabels = ['Ma', 'Di', 'Wo', 'Do', 'Vr', 'Za', 'Zo'];
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kantoordagen — Janus</title>
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
    <link rel="manifest" href="site.webmanifest">
    <link rel="stylesheet" href="brand.css">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--kvt-page-bg); color: var(--kvt-text); }
        .wrap { max-width: 480px; margin: 0 auto; padding: 12px 14px 28px; }
        .topbar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: space-between; margin-bottom: 12px; }
        .brand-row { display: flex; align-items: center; gap: 10px; }
        .brand-row img { height: 36px; width: auto; }
        .brand-row h1 { margin: 0; font-size: 1.35rem; color: var(--kvt-perkins-blue); }
        .top-actions { display: flex; flex-wrap: wrap; gap: 8px; }
        .btn, button {
            appearance: none; border: 1px solid var(--kvt-line); background: #fff; color: var(--kvt-text);
            border-radius: 4px; padding: 8px 10px; font-size: 0.9rem; cursor: pointer; text-decoration: none;
        }
        .panel {
            border: 1px solid var(--kvt-line); border-radius: 8px; padding: 14px; background: #fff;
        }
        .sub { color: var(--kvt-muted); font-size: 0.88rem; margin: 0 0 12px; }
        .cal-nav {
            display: grid; grid-template-columns: 44px 1fr 44px; gap: 8px; align-items: center; margin-bottom: 12px;
        }
        .cal-nav .month-label {
            text-align: center; font-weight: 700; color: var(--kvt-perkins-blue); text-transform: capitalize;
        }
        .cal-weekdays, .cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 6px; }
        .cal-weekdays span {
            text-align: center; font-size: 0.75rem; color: var(--kvt-muted); font-weight: 700;
        }
        .cal-grid { margin-top: 6px; }
        .cal-day {
            appearance: none; border: 1px solid var(--kvt-line); background: #fff; border-radius: 8px;
            min-height: 56px; padding: 4px; font: inherit; cursor: pointer;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 2px; line-height: 1.1;
        }
        .cal-day.empty { border: 0; background: transparent; cursor: default; min-height: 56px; }
        .cal-day .emoji { font-size: 1.05rem; line-height: 1; }
        .cal-day .num { font-size: 0.82rem; font-weight: 700; }
        .cal-day.today {
            border-color: var(--kvt-main-blue);
            box-shadow: inset 0 0 0 1px var(--kvt-main-blue);
            color: var(--kvt-main-blue);
            background: #e8f3fb;
        }
        .cal-day.office {
            background: #f0f7fc;
            border-color: #c5dced;
        }
        .cal-day.office.today {
            background: #e8f3fb;
            border-color: var(--kvt-main-blue);
        }
        .cal-day.off { background: #e8ebef; color: #7a8794; border-color: #d3d9e0; }
        .cal-day.off.office { background: #e6eef5; }
        .cal-day:not(.empty):hover { border-color: var(--kvt-main-blue); }
        .switch-link-wrap { text-align: center; margin-top: 28px; padding-bottom: 8px; }
        .switch-link {
            color: var(--kvt-muted); font-size: 0.72rem; font-weight: 400; text-decoration: underline;
            background: none; border: 0; padding: 0; cursor: pointer;
        }
        .switch-link:hover { color: var(--kvt-perkins-blue); }
        .range-modal {
            position: fixed; inset: 0; background: rgba(0,0,0,.45); display: none;
            align-items: center; justify-content: center; z-index: 2000; padding: 14px;
        }
        .range-modal.open { display: flex; }
        .range-modal-card {
            width: min(420px, 100%); background: #fff; border-radius: 8px; padding: 16px;
            box-shadow: 0 20px 40px rgba(0,0,0,.3);
        }
        .range-modal-card h3 { margin: 0 0 8px; color: var(--kvt-perkins-blue); }
        .range-modal-card p { margin: 0 0 14px; line-height: 1.4; }
        .range-modal-actions { display: flex; gap: 8px; justify-content: flex-end; flex-wrap: wrap; }
        .btn-primary { background: var(--kvt-main-blue); border-color: var(--kvt-main-blue); color: #fff; }
        .office-grid {
            display: grid; grid-template-columns: repeat(7, 1fr); gap: 6px; margin: 10px 0 14px;
        }
        .office-day {
            display: flex; flex-direction: column; align-items: center; gap: 6px;
            padding: 8px 4px; border: 1px solid var(--kvt-line); border-radius: 6px;
            font-size: 0.8rem; font-weight: 700; cursor: pointer; user-select: none; background: #fff;
        }
        .office-day input { margin: 0; }
        .setup-error { color: #b42318; font-size: 0.85rem; margin: 0 0 10px; display: none; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="topbar">
        <div class="brand-row">
            <img src="logo-website.png" alt="KVT">
            <h1 class="brand-display">Kantoordagen</h1>
        </div>
        <div class="top-actions">
            <?php if ($canAccessOverview): ?>
                <a class="btn" href="overview.php">Overzicht</a>
            <?php endif; ?>
            <?php if ($canAccessOfficeDays): ?>
                <a class="btn" href="office_days.php">Beheer</a>
            <?php endif; ?>
            <a class="btn" href="settings.php">Instellingen</a>
        </div>
    </div>

    <div class="panel">
        <p class="sub">Tik op een dag om thuis (🏠) of kantoor (🏢) te markeren.</p>
        <div class="cal-nav">
            <button type="button" class="btn" id="prevMonth" title="Vorige maand">←</button>
            <div class="month-label" id="monthLabel">—</div>
            <button type="button" class="btn" id="nextMonth" title="Volgende maand">→</button>
        </div>
        <div class="cal-weekdays">
            <span>Ma</span><span>Di</span><span>Wo</span><span>Do</span><span>Vr</span><span>Za</span><span>Zo</span>
        </div>
        <div class="cal-grid" id="calGrid"></div>
    </div>

    <div class="switch-link-wrap">
        <button type="button" class="switch-link" id="switchFullLink">Overschakelen naar volledige urentracker</button>
    </div>
</div>

<div class="range-modal<?= $needsSetup ? ' open' : '' ?>" id="setupModal" aria-hidden="<?= $needsSetup ? 'false' : 'true' ?>">
    <div class="range-modal-card">
        <h3>Standaard kantoordagen</h3>
        <p>Welke dagen werk je standaard op kantoor? Je kunt dit later aanpassen in Instellingen.</p>
        <div class="setup-error" id="setupError">Opslaan mislukt.</div>
        <div class="office-grid" id="setupOfficeGrid">
            <?php foreach ($officeLabels as $i => $label): ?>
                <label class="office-day">
                    <span><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    <input type="checkbox" data-index="<?= (int) $i ?>" <?= !empty($defaultOffice[$i]) ? 'checked' : '' ?>>
                </label>
            <?php endforeach; ?>
        </div>
        <div class="range-modal-actions">
            <button type="button" class="btn btn-primary" id="setupConfirm">Doorgaan</button>
        </div>
    </div>
</div>

<div class="range-modal" id="switchModal" aria-hidden="true">
    <div class="range-modal-card">
        <p>Klik op Instellingen om terug te gaan naar de light-view.</p>
        <div class="range-modal-actions">
            <button type="button" class="btn" id="switchCancel">Terug naar Light-view</button>
            <button type="button" class="btn btn-primary" id="switchConfirm">OK</button>
        </div>
    </div>
</div>

<script>
(function () {
    var needsSetup = <?= $needsSetup ? 'true' : 'false' ?>;
    var calGrid = document.getElementById('calGrid');
    var monthLabel = document.getElementById('monthLabel');
    var prevMonth = document.getElementById('prevMonth');
    var nextMonth = document.getElementById('nextMonth');
    var switchModal = document.getElementById('switchModal');
    var setupModal = document.getElementById('setupModal');
    var setupConfirm = document.getElementById('setupConfirm');
    var setupError = document.getElementById('setupError');
    var switchFullLink = document.getElementById('switchFullLink');
    var switchCancel = document.getElementById('switchCancel');
    var switchConfirm = document.getElementById('switchConfirm');
    var toggleBusy = false;
    var view = (function () {
        var now = new Date();
        return { year: now.getFullYear(), month: now.getMonth() + 1 };
    })();

    function closeSwitchModal() {
        switchModal.classList.remove('open');
        switchModal.setAttribute('aria-hidden', 'true');
    }

    function openSwitchModal() {
        switchModal.classList.add('open');
        switchModal.setAttribute('aria-hidden', 'false');
    }

    function closeSetupModal() {
        setupModal.classList.remove('open');
        setupModal.setAttribute('aria-hidden', 'true');
        needsSetup = false;
    }

    function shiftMonth(delta) {
        view.month += delta;
        if (view.month < 1) {
            view.month = 12;
            view.year -= 1;
        } else if (view.month > 12) {
            view.month = 1;
            view.year += 1;
        }
        loadMonth();
    }

    function renderMonth(payload) {
        monthLabel.textContent = payload.label || (payload.month + '-' + payload.year);
        var html = '';
        var pad = Number(payload.startWeekday || 0);
        for (var i = 0; i < pad; i++) {
            html += '<div class="cal-day empty"></div>';
        }
        (payload.days || []).forEach(function (day) {
            var classes = ['cal-day'];
            if (day.today) classes.push('today');
            if (day.off) classes.push('off');
            if (day.office) classes.push('office');
            var emoji = day.office ? '🏢' : '🏠';
            html += '<button type="button" class="' + classes.join(' ') + '"'
                + ' data-iso="' + day.iso + '"'
                + ' data-office="' + (day.office ? '1' : '0') + '"'
                + '>'
                + '<span class="emoji">' + emoji + '</span>'
                + '<span class="num">' + day.day + '</span>'
                + '</button>';
        });
        calGrid.innerHTML = html;
        Array.prototype.forEach.call(calGrid.querySelectorAll('.cal-day:not(.empty)'), function (btn) {
            btn.addEventListener('click', function () { toggleDay(btn); });
        });
    }

    function loadMonth() {
        if (needsSetup) {
            calGrid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:16px;color:var(--kvt-muted)">Stel eerst je standaard kantoordagen in.</div>';
            return;
        }
        calGrid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:16px;color:var(--kvt-muted)">Laden...</div>';
        fetch('hours_office_light.php?year=' + encodeURIComponent(view.year)
            + '&month=' + encodeURIComponent(view.month)
            + '&_ts=' + Date.now(), { cache: 'no-store' })
            .then(function (resp) { return resp.json().then(function (payload) { return { status: resp.status, payload: payload }; }); })
            .then(function (result) {
                if (result.payload && result.payload.needsSetup) {
                    needsSetup = true;
                    setupModal.classList.add('open');
                    setupModal.setAttribute('aria-hidden', 'false');
                    loadMonth();
                    return;
                }
                if (!result.payload || !result.payload.ok) throw new Error('invalid');
                view.year = result.payload.year;
                view.month = result.payload.month;
                renderMonth(result.payload);
            })
            .catch(function () {
                calGrid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:16px;color:#b42318">Laden mislukt.</div>';
            });
    }

    function toggleDay(btn) {
        if (toggleBusy || needsSetup) return;
        var iso = btn.getAttribute('data-iso') || '';
        var currentlyOffice = btn.getAttribute('data-office') === '1';
        var enable = !currentlyOffice;
        toggleBusy = true;
        var form = new FormData();
        form.append('day', iso);
        if (enable) form.append('office', '1');
        fetch('hours_office_light.php', { method: 'POST', body: form, cache: 'no-store' })
            .then(function (resp) { return resp.json(); })
            .then(function (payload) {
                toggleBusy = false;
                if (!payload || !payload.ok) throw new Error('invalid');
                loadMonth();
            })
            .catch(function () {
                toggleBusy = false;
                loadMonth();
            });
    }

    function submitSetup() {
        setupError.style.display = 'none';
        var form = new FormData();
        form.append('action', 'setup');
        var names = [
            'DefaultOfficeMon', 'DefaultOfficeTue', 'DefaultOfficeWed', 'DefaultOfficeThu',
            'DefaultOfficeFri', 'DefaultOfficeSat', 'DefaultOfficeSun'
        ];
        Array.prototype.forEach.call(setupModal.querySelectorAll('input[type="checkbox"]'), function (input) {
            var idx = Number(input.getAttribute('data-index') || 0);
            if (input.checked && names[idx]) {
                form.append(names[idx], '1');
            }
        });
        setupConfirm.disabled = true;
        fetch('hours_office_light.php', { method: 'POST', body: form, cache: 'no-store' })
            .then(function (resp) { return resp.json(); })
            .then(function (payload) {
                setupConfirm.disabled = false;
                if (!payload || !payload.ok) throw new Error('invalid');
                closeSetupModal();
                loadMonth();
            })
            .catch(function () {
                setupConfirm.disabled = false;
                setupError.style.display = 'block';
            });
    }

    function switchToFull() {
        var form = new FormData();
        form.append('light', '0');
        fetch('tracker_mode.php', { method: 'POST', body: form, cache: 'no-store' })
            .then(function (resp) { return resp.json(); })
            .then(function (payload) {
                if (!payload || !payload.ok) throw new Error('invalid');
                window.location.href = 'index.php';
            })
            .catch(function () {
                closeSwitchModal();
                alert('Overschakelen mislukt.');
            });
    }

    prevMonth.addEventListener('click', function () { shiftMonth(-1); });
    nextMonth.addEventListener('click', function () { shiftMonth(1); });
    switchFullLink.addEventListener('click', openSwitchModal);
    switchCancel.addEventListener('click', closeSwitchModal);
    switchConfirm.addEventListener('click', switchToFull);
    setupConfirm.addEventListener('click', submitSetup);
    switchModal.addEventListener('click', function (e) {
        if (e.target === switchModal) closeSwitchModal();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && switchModal.classList.contains('open')) closeSwitchModal();
    });

    loadMonth();
})();
</script>
</body>
</html>
