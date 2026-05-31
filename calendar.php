<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

$coachId = (int)getCurrentCoachId();
$pdo = getDB();

$athleteStmt = $pdo->prepare(
    'SELECT id, first_name, last_name
     FROM athletes
     WHERE coach_id = ?
     ORDER BY last_name, first_name'
);
$athleteStmt->execute([$coachId]);
$athletes = $athleteStmt->fetchAll();

$venueStmt = $pdo->prepare(
    'SELECT DISTINCT location
     FROM coach_calendar_events
     WHERE coach_id = ? AND location IS NOT NULL
     ORDER BY location ASC'
);
$venueStmt->execute([$coachId]);
$venues = array_map(fn($row) => $row['location'], $venueStmt->fetchAll());

renderHeader('Kalendář');
?>

<style>
.calendar-shell {
    overflow-x: auto;
    border-radius: 12px;
}

.calendar-grid {
    width: 100%;
    table-layout: fixed;
    border-collapse: separate;
    border-spacing: 0;
    min-width: 100%;
}

.calendar-grid th,
.calendar-grid td {
    border: 1px solid #e5e7eb;
    vertical-align: top;
    padding: .35rem;
}

.calendar-grid thead th {
    background: #111827;
    color: #f9fafb;
    position: sticky;
    top: 0;
    z-index: 2;
}

.calendar-grid .time-col {
    width: 72px;
    min-width: 72px;
    text-align: center;
    font-weight: 700;
    background: #f8fafc;
    color: #374151;
}

.calendar-grid .slot-cell {
    height: 64px;
    cursor: pointer;
    background: #ffffff;
    transition: background .12s ease-in-out;
}

.calendar-grid .slot-cell:hover {
    background: #fffbeb;
}

.calendar-grid .slot-cell.is-locked {
    background: repeating-linear-gradient(
        45deg,
        #f3f4f6,
        #f3f4f6 8px,
        #e5e7eb 8px,
        #e5e7eb 16px
    );
    cursor: not-allowed;
}

.slot-event {
    display: block;
    width: 100%;
    text-align: left;
    border: 0;
    border-radius: 10px;
    padding: .35rem .45rem;
    background: #0ea5e9;
    color: #fff;
    line-height: 1.15;
    font-size: .78rem;
    font-weight: 700;
}

.slot-event.pending {
    background: #f97316;
    color: #ffffff;
    border: 2px solid #fff7ed;
    animation: pendingPulse .72s ease-in-out infinite alternate;
}

.slot-event.updated {
    box-shadow: inset 0 0 0 2px rgba(255,255,255,.45);
}

#daypilotCalendar .coach-calendar-pending {
    animation: pendingPulse .72s ease-in-out infinite alternate;
}

@keyframes pendingPulse {
    0% {
        opacity: 1;
        transform: scale(1);
        filter: saturate(1) brightness(1);
        box-shadow: 0 0 0 0 rgba(249, 115, 22, .0);
    }
    100% {
        opacity: .9;
        transform: scale(1.03);
        filter: saturate(1.35) brightness(1.08);
        box-shadow: 0 0 0 6px rgba(249, 115, 22, .45);
    }
}

@media (prefers-reduced-motion: reduce) {
    .slot-event.pending,
    #daypilotCalendar .coach-calendar-pending {
        animation: none;
    }
}

.slot-event .time {
    font-weight: 600;
    font-size: .75rem;
}

.slot-event .where {
    display: block;
    font-size: .68rem;
    opacity: .95;
    margin-top: 2px;
    font-weight: 500;
}

.slot-add-hint {
    color: #9ca3af;
    font-size: .72rem;
    margin-top: .1rem;
    text-align: center;
}

.lock-chip {
    display: inline-block;
    border-radius: 999px;
    padding: .08rem .45rem;
    font-size: .67rem;
    font-weight: 700;
    background: #374151;
    color: #fff;
}

.lock-list-item {
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: .55rem .6rem;
}

.request-banner {
    border-radius: 10px;
    padding: .65rem .8rem;
    background: #fff7ed;
    border: 1px solid #fdba74;
    color: #9a3412;
    font-size: .9rem;
}

@media (max-width: 991.98px) {
    .calendar-grid th,
    .calendar-grid td {
        padding: .22rem;
    }

    .calendar-grid .time-col {
        width: 52px;
        min-width: 52px;
        font-size: .68rem;
    }

    .calendar-grid .slot-cell {
        height: 52px;
    }

    .calendar-grid .day-name {
        display: block;
        font-size: .65rem;
        line-height: 1;
    }

    .calendar-grid .day-date {
        display: block;
        font-size: .68rem;
    }

    .slot-event {
        font-size: .62rem;
        padding: .22rem .3rem;
        border-radius: 8px;
    }

    .slot-event .where {
        font-size: .56rem;
        margin-top: 1px;
    }

    .slot-add-hint {
        font-size: .58rem;
    }
}
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h2 class="mb-0"><i class="fas fa-calendar-alt me-2 text-warning"></i>Kalendář trenéra</h2>
    <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-outline-secondary btn-sm" id="prevWeekBtn">
            <i class="fas fa-chevron-left me-1"></i>Předchozí týden
        </button>
        <button class="btn btn-outline-dark btn-sm" id="todayWeekBtn">
            Tento týden
        </button>
        <button class="btn btn-outline-secondary btn-sm" id="nextWeekBtn">
            Další týden<i class="fas fa-chevron-right ms-1"></i>
        </button>
        <button class="btn btn-warning btn-sm fw-bold" id="quickAddBtn">
            <i class="fas fa-plus me-1"></i>Přidat trénink
        </button>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <div class="fw-bold" id="weekRangeLabel">Načítám týden…</div>
            <div class="text-muted small">Klikněte do slotu pro přidání tréninku nebo uzamčení času.</div>
        </div>
        <div class="d-flex align-items-center gap-2 small flex-wrap">
            <span class="badge text-bg-info">Trénink</span>
            <span class="badge" style="background:#f97316;color:#fff">Ke schválení</span>
            <span class="lock-chip">Uzamčeno</span>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3" id="daypilotCard">
    <div class="card-body p-2 p-md-3">
        <div id="daypilotCalendar"></div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-2 p-md-3">
        <div class="calendar-shell">
            <table class="calendar-grid" id="calendarGrid" aria-label="Týdenní kalendář"></table>
        </div>
    </div>
</div>

<div class="modal fade" id="eventModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="eventForm">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title" id="eventModalTitle"><i class="fas fa-calendar-plus me-2 text-warning"></i>Trénink</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="eventId" value="">
                    <input type="hidden" id="lockId" value="">

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="eventIsLock">
                        <label class="form-check-label fw-semibold" for="eventIsLock">Uzamknout časy (místo tréninku)</label>
                    </div>

                    <div id="eventTrainingFields">

                    <div class="mb-3">
                        <label for="eventAthlete" class="form-label fw-semibold">Sportovec</label>
                        <select id="eventAthlete" class="form-select">
                            <option value="">-- Bez sportovce --</option>
                            <?php foreach ($athletes as $a): ?>
                            <option value="<?= (int)$a['id'] ?>">
                                <?= h($a['last_name'] . ' ' . $a['first_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Vyberte svého sportovce, nebo níže napište vlastní název.</div>
                    </div>

                    <div class="mb-3">
                        <label for="eventCustomTitle" class="form-label fw-semibold">Název vlastní</label>
                        <input type="text" id="eventCustomTitle" class="form-control" maxlength="140" placeholder="Např. Konzultace / regenerace / soukromý trénink">
                    </div>

                    <div class="mb-3">
                        <label for="eventLocationMode" class="form-label fw-semibold">Místo konání</label>
                        <div class="input-group">
                            <select id="eventLocationMode" class="form-select" style="flex: 0 0 140px">
                                <option value="custom">Napsat sám</option>
                                <?php foreach ($venues as $venue): ?>
                                <option value="<?= h($venue) ?>"><?= h($venue) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" id="eventLocation" class="form-control" maxlength="255" placeholder="Např. Stadion, fitko, hala, venku...">
                        </div>
                        <div class="form-text">Vyberte existující místo, nebo zadejte vlastní.</div>
                    </div>

                    <div class="mb-3">
                        <label for="eventColor" class="form-label fw-semibold">Barva události</label>
                        <select id="eventColor" class="form-select">
                            <option value="blue">Modrá</option>
                            <option value="green" selected>Zelená</option>
                            <option value="red">Červená</option>
                            <option value="orange">Oranžová</option>
                            <option value="purple">Fialová</option>
                            <option value="gray">Šedá</option>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label for="eventDate" class="form-label fw-semibold">Čas začátku</label>
                        <div class="row g-2">
                            <div class="col-md-7">
                                <input type="date" id="eventDate" class="form-control" required>
                            </div>
                            <div class="col-3 col-md-3">
                                <select id="eventHour" class="form-select" required></select>
                            </div>
                            <div class="col-3 col-md-2">
                                <select id="eventMinute" class="form-select" required>
                                    <option value="00">00</option>
                                    <option value="15">15</option>
                                    <option value="30">30</option>
                                    <option value="45">45</option>
                                </select>
                            </div>
                        </div>
                        <input type="hidden" id="eventStart" value="">
                    </div>
                    <div class="small text-muted">Délka je vždy pevně 60 minut.</div>

                    <div class="mt-3">
                        <label for="eventRepeatMode" class="form-label fw-semibold">Opakování</label>
                        <select id="eventRepeatMode" class="form-select">
                            <option value="none">Neopakovat</option>
                            <option value="weekly_until_date">Každý týden do data</option>
                            <option value="weekly_end_of_next_month">Každý týden do konce příštího měsíce</option>
                            <option value="weekly_end_of_year">Každý týden do konce roku</option>
                        </select>
                    </div>

                    <div class="mt-2 d-none" id="eventRepeatUntilWrap">
                        <label for="eventRepeatUntil" class="form-label fw-semibold">Opakovat do</label>
                        <input type="date" id="eventRepeatUntil" class="form-control">
                    </div>

                    <div class="small text-muted mt-1" id="eventRepeatHint"></div>

                    <div class="mt-3">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" role="switch" id="eventIsMakeup">
                            <label class="form-check-label fw-semibold" for="eventIsMakeup">Náhradní termín</label>
                        </div>
                        <div class="small text-muted">Náhradní termín se může započítat do jiného hrazeného měsíce.</div>
                    </div>

                    <div class="mt-2 d-none" id="eventBillingMonthWrap">
                        <label for="eventBillingMonth" class="form-label fw-semibold">Hrazený měsíc</label>
                        <input type="month" id="eventBillingMonth" class="form-control">
                        <div class="form-text">Použije se pro stránku Platby. Pokud je to náhrada za dříve zaplacený měsíc, vyberte ten původní.</div>
                    </div>

                    </div>

                    <div id="lockFields" class="d-none">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="lockUnlockMode">
                            <label class="form-check-label fw-semibold" for="lockUnlockMode">Odemknout vybraný interval</label>
                        </div>

                        <div class="mb-3">
                            <label for="lockNoteInline" class="form-label fw-semibold">Poznámka (volitelně)</label>
                            <input type="text" id="lockNoteInline" class="form-control" maxlength="255" placeholder="Např. Mimo práci / dovolená / administrativa">
                        </div>

                        <div class="mb-3">
                            <label for="lockStartDate" class="form-label fw-semibold">Od</label>
                            <div class="row g-2">
                                <div class="col-6 col-sm-7">
                                    <input type="date" id="lockStartDate" class="form-control">
                                </div>
                                <div class="col-3 col-sm-3">
                                    <select id="lockStartHour" class="form-select"></select>
                                </div>
                                <div class="col-3 col-sm-2">
                                    <select id="lockStartMinute" class="form-select">
                                        <option value="00">00</option>
                                        <option value="15">15</option>
                                        <option value="30">30</option>
                                        <option value="45">45</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="mb-2">
                            <label for="lockEndDate" class="form-label fw-semibold">Do</label>
                            <div class="row g-2">
                                <div class="col-6 col-sm-7">
                                    <input type="date" id="lockEndDate" class="form-control">
                                </div>
                                <div class="col-3 col-sm-3">
                                    <select id="lockEndHour" class="form-select"></select>
                                </div>
                                <div class="col-3 col-sm-2">
                                    <select id="lockEndMinute" class="form-select">
                                        <option value="00">00</option>
                                        <option value="15">15</option>
                                        <option value="30">30</option>
                                        <option value="45">45</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <input type="hidden" id="lockRangeStart" value="">
                        <input type="hidden" id="lockRangeEnd" value="">

                        <div class="mt-3">
                            <label for="lockRepeatMode" class="form-label fw-semibold">Opakování uzamčení</label>
                            <select id="lockRepeatMode" class="form-select">
                                <option value="none">Neopakovat</option>
                                <option value="weekly_until_date">Každý týden do data</option>
                                <option value="weekly_end_of_next_month">Každý týden do konce příštího měsíce</option>
                                <option value="weekly_end_of_year">Každý týden do konce roku</option>
                            </select>
                        </div>

                        <div class="mt-2 d-none" id="lockRepeatUntilWrap">
                            <label for="lockRepeatUntil" class="form-label fw-semibold">Uzamykat do</label>
                            <input type="date" id="lockRepeatUntil" class="form-control">
                        </div>

                        <div class="small text-muted mt-1" id="lockRepeatHint"></div>
                    </div>

                    <div id="eventError" class="alert alert-danger py-2 px-3 mt-3 mb-0 d-none"></div>
                    <div id="requestInfo" class="request-banner mt-3 d-none"></div>
                </div>
                <div class="modal-footer d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-danger me-auto d-none" id="deleteEventBtn">
                        <i class="fas fa-trash me-1"></i>Smazat
                    </button>
                    <button type="button" class="btn btn-success d-none" id="approveEventBtn">
                        <i class="fas fa-check me-1"></i>Schválit
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Zrušit</button>
                    <button type="submit" class="btn btn-warning fw-bold">
                        <i class="fas fa-save me-1"></i>Uložit
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://unpkg.com/@daypilot/daypilot-lite-javascript@5.6.0/daypilot-javascript.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const csrfToken = <?= json_encode(csrfToken(), JSON_UNESCAPED_UNICODE) ?>;
    const calendarGrid = document.getElementById('calendarGrid');
    const weekRangeLabel = document.getElementById('weekRangeLabel');

    const eventModalEl = document.getElementById('eventModal');
    const eventModal = new bootstrap.Modal(eventModalEl);
    const eventModalTitle = document.getElementById('eventModalTitle');

    const eventForm = document.getElementById('eventForm');
    const eventIdInput = document.getElementById('eventId');
    const lockIdInput = document.getElementById('lockId');
    const eventIsLockInput = document.getElementById('eventIsLock');
    const eventTrainingFields = document.getElementById('eventTrainingFields');
    const lockFields = document.getElementById('lockFields');
    const eventAthleteInput = document.getElementById('eventAthlete');
    const eventCustomTitleInput = document.getElementById('eventCustomTitle');
    const eventLocationModeInput = document.getElementById('eventLocationMode');
    const eventLocationInput = document.getElementById('eventLocation');
    const eventColorInput = document.getElementById('eventColor');
    const eventDateInput = document.getElementById('eventDate');
    const eventHourInput = document.getElementById('eventHour');
    const eventMinuteInput = document.getElementById('eventMinute');
    const eventStartInput = document.getElementById('eventStart');
    const eventRepeatModeInput = document.getElementById('eventRepeatMode');
    const eventRepeatUntilWrap = document.getElementById('eventRepeatUntilWrap');
    const eventRepeatUntilInput = document.getElementById('eventRepeatUntil');
    const eventRepeatHint = document.getElementById('eventRepeatHint');
    const eventIsMakeupInput = document.getElementById('eventIsMakeup');
    const eventBillingMonthWrap = document.getElementById('eventBillingMonthWrap');
    const eventBillingMonthInput = document.getElementById('eventBillingMonth');
    const lockUnlockModeInput = document.getElementById('lockUnlockMode');
    const lockNoteInlineInput = document.getElementById('lockNoteInline');
    const lockStartDateInput = document.getElementById('lockStartDate');
    const lockStartHourInput = document.getElementById('lockStartHour');
    const lockStartMinuteInput = document.getElementById('lockStartMinute');
    const lockEndDateInput = document.getElementById('lockEndDate');
    const lockEndHourInput = document.getElementById('lockEndHour');
    const lockEndMinuteInput = document.getElementById('lockEndMinute');
    const lockRangeStartInput = document.getElementById('lockRangeStart');
    const lockRangeEndInput = document.getElementById('lockRangeEnd');
    const lockRepeatModeInput = document.getElementById('lockRepeatMode');
    const lockRepeatUntilWrap = document.getElementById('lockRepeatUntilWrap');
    const lockRepeatUntilInput = document.getElementById('lockRepeatUntil');
    const lockRepeatHint = document.getElementById('lockRepeatHint');
    const eventError = document.getElementById('eventError');
    const requestInfo = document.getElementById('requestInfo');
    const deleteEventBtn = document.getElementById('deleteEventBtn');
    const approveEventBtn = document.getElementById('approveEventBtn');
    const daypilotCalendarEl = document.getElementById('daypilotCalendar');
    const daypilotCard = document.getElementById('daypilotCard');

    const czechDayShort = ['Po', 'Út', 'St', 'Čt', 'Pá', 'So', 'Ne'];
    const hourStart = 5;
    const hourEnd = 22;
    const eventColorSchemes = {
        blue: { backColor: '#0ea5e9', barColor: '#0284c7', fontColor: '#ffffff' },
        green: { backColor: '#22c55e', barColor: '#16a34a', fontColor: '#ffffff' },
        red: { backColor: '#ef4444', barColor: '#dc2626', fontColor: '#ffffff' },
        orange: { backColor: '#f97316', barColor: '#ea580c', fontColor: '#ffffff' },
        purple: { backColor: '#8b5cf6', barColor: '#7c3aed', fontColor: '#ffffff' },
        gray: { backColor: '#6b7280', barColor: '#4b5563', fontColor: '#ffffff' },
    };

    let currentWeekStart = getMonday(new Date());
    let events = [];
    let locks = [];
    let dayPilotCalendar = null;
    let activeEvent = null;

    function normalizeColorKey(colorKey) {
        if (typeof colorKey !== 'string') {
            return 'green';
        }
        return Object.prototype.hasOwnProperty.call(eventColorSchemes, colorKey) ? colorKey : 'green';
    }

    function getEventColorScheme(event) {
        if ((event.approval_status || 'approved') === 'pending') {
            return { backColor: '#f97316', barColor: '#ea580c', fontColor: '#ffffff' };
        }

        return eventColorSchemes[normalizeColorKey(event.color_key)];
    }

    function getEventStatusMeta(event) {
        if ((event.approval_status || 'approved') === 'pending') {
            return {
                label: 'Ke schválení',
                className: 'pending',
            };
        }

        if (event.coach_modified_at) {
            return {
                label: 'Upraveno trenérem',
                className: 'updated',
            };
        }

        return {
            label: '',
            className: '',
        };
    }

    function getMonday(date) {
        const d = new Date(date);
        d.setHours(0, 0, 0, 0);
        const day = d.getDay();
        const diff = d.getDate() - (day === 0 ? 6 : day - 1);
        d.setDate(diff);
        return d;
    }

    function addDays(date, days) {
        const d = new Date(date);
        d.setDate(d.getDate() + days);
        return d;
    }

    function toDateKey(date) {
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const d = String(date.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    }

    function toMonthKey(date) {
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        return `${y}-${m}`;
    }

    function toDateTimeInputValue(date) {
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const d = String(date.getDate()).padStart(2, '0');
        const h = String(date.getHours()).padStart(2, '0');
        const min = String(date.getMinutes()).padStart(2, '0');
        return `${y}-${m}-${d}T${h}:${min}`;
    }

    function toDateTimeSecondsValue(date) {
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const d = String(date.getDate()).padStart(2, '0');
        const h = String(date.getHours()).padStart(2, '0');
        const min = String(date.getMinutes()).padStart(2, '0');
        return `${y}-${m}-${d}T${h}:${min}:00`;
    }

    function dayPilotDateToJs(value) {
        if (!value) {
            return null;
        }
        const raw = typeof value.toString === 'function' ? value.toString() : String(value);
        return new Date(raw.replace(' ', 'T'));
    }

    function isRangeLocked(start, end) {
        return locks.some((lock) => {
            const lockStart = fromSqlDateTime(lock.starts_at);
            const lockEnd = fromSqlDateTime(lock.ends_at);
            return lockStart < end && lockEnd > start;
        });
    }

    function toDayPilotEvent(event) {
        const startDate = fromSqlDateTime(event.starts_at);
        const endDate = fromSqlDateTime(event.ends_at);
        const title = getEventTitle(event);
        const timeLabel = `${formatTimeCs(startDate)} - ${formatTimeCs(endDate)}`;
        const placeLabel = event.location ? `${event.location}` : '';
        const athleteLabel = event.athlete_id && event.first_name && event.last_name ? `${event.last_name} ${event.first_name}` : '';
        const place = event.location ? `\nMísto: ${event.location}` : '';
        const time = `\nČas: ${formatTimeCs(startDate)} - ${formatTimeCs(endDate)}`;
        const color = getEventColorScheme(event);
        const statusMeta = getEventStatusMeta(event);
        const statusLine = statusMeta.label ? `\nStav: ${statusMeta.label}` : '';
        const detailLine = [statusMeta.label, timeLabel, placeLabel, athleteLabel].filter(Boolean).join(' | ');

        return {
            id: String(event.id),
            text: [title, detailLine].filter(Boolean).join('\n'),
            toolTip: `${title}${time}${place}${statusLine}`,
            start: toDateTimeSecondsValue(startDate),
            end: toDateTimeSecondsValue(endDate),
            backColor: color.backColor,
            barColor: color.barColor,
            fontColor: color.fontColor,
            cssClass: statusMeta.className === 'pending' ? 'coach-calendar-pending' : '',
        };
    }

    function toDayPilotLockEvent(lock) {
        const startDate = fromSqlDateTime(lock.starts_at);
        const endDate = fromSqlDateTime(lock.ends_at);
        const note = lock.note ? `\nPoznámka: ${lock.note}` : '';

        return {
            id: `lock-${lock.id}`,
            text: 'Uzamčeno',
            toolTip: `Uzamčeno\nČas: ${formatTimeCs(startDate)} - ${formatTimeCs(endDate)}${note}`,
            start: toDateTimeSecondsValue(startDate),
            end: toDateTimeSecondsValue(endDate),
            backColor: '#9ca3af',
            barColor: '#6b7280',
            fontColor: '#111827',
            moveDisabled: true,
            resizeDisabled: true,
        };
    }

    function snapDateToQuarterHour(date) {
        const d = new Date(date);
        d.setSeconds(0, 0);

        const minutes = d.getMinutes();
        const snappedMinutes = Math.round(minutes / 15) * 15;
        if (snappedMinutes >= 60) {
            d.setHours(d.getHours() + 1, 0, 0, 0);
        } else {
            d.setMinutes(snappedMinutes, 0, 0);
        }

        return d;
    }

    function normalizeEventStartInputToQuarterHour() {
        if (!eventStartInput.value) {
            return;
        }

        const parsed = new Date(eventStartInput.value);
        if (Number.isNaN(parsed.getTime())) {
            return;
        }

        eventStartInput.value = toDateTimeInputValue(snapDateToQuarterHour(parsed));
    }

    function populateEventHourOptions() {
        eventHourInput.innerHTML = '';
        for (let hour = hourStart; hour < hourEnd; hour++) {
            const value = String(hour).padStart(2, '0');
            const option = document.createElement('option');
            option.value = value;
            option.textContent = value;
            eventHourInput.appendChild(option);
        }
    }

    function populateLockHourOptions(targetSelect) {
        targetSelect.innerHTML = '';
        for (let hour = 0; hour < 24; hour++) {
            const value = String(hour).padStart(2, '0');
            const option = document.createElement('option');
            option.value = value;
            option.textContent = value;
            targetSelect.appendChild(option);
        }
    }

    function syncEventStartFromControls() {
        const date = eventDateInput.value;
        const hour = eventHourInput.value;
        const minute = eventMinuteInput.value;

        if (!date || !hour || !minute) {
            eventStartInput.value = '';
            return;
        }

        eventStartInput.value = `${date}T${hour}:${minute}`;
    }

    function setEventStartControls(date) {
        const snapped = snapDateToQuarterHour(date);
        const minute = String(snapped.getMinutes()).padStart(2, '0');

        eventDateInput.value = toDateKey(snapped);
        eventHourInput.value = String(snapped.getHours()).padStart(2, '0');
        eventMinuteInput.value = ['00', '15', '30', '45'].includes(minute) ? minute : '00';

        syncEventStartFromControls();
    }

    function syncLockRangeFromControls() {
        const startDate = lockStartDateInput.value;
        const startHour = lockStartHourInput.value;
        const startMinute = lockStartMinuteInput.value;
        const endDate = lockEndDateInput.value;
        const endHour = lockEndHourInput.value;
        const endMinute = lockEndMinuteInput.value;

        if (!startDate || !startHour || !startMinute) {
            lockRangeStartInput.value = '';
        } else {
            lockRangeStartInput.value = `${startDate}T${startHour}:${startMinute}`;
        }

        if (!endDate || !endHour || !endMinute) {
            lockRangeEndInput.value = '';
        } else {
            lockRangeEndInput.value = `${endDate}T${endHour}:${endMinute}`;
        }
    }

    function setLockRangeControls(startDate, endDate) {
        const snappedStart = snapDateToQuarterHour(startDate);
        let snappedEnd = snapDateToQuarterHour(endDate);

        if (snappedEnd <= snappedStart) {
            snappedEnd = new Date(snappedStart);
            snappedEnd.setMinutes(snappedEnd.getMinutes() + 60);
        }

        lockStartDateInput.value = toDateKey(snappedStart);
        lockStartHourInput.value = String(snappedStart.getHours()).padStart(2, '0');
        lockStartMinuteInput.value = String(snappedStart.getMinutes()).padStart(2, '0');

        lockEndDateInput.value = toDateKey(snappedEnd);
        lockEndHourInput.value = String(snappedEnd.getHours()).padStart(2, '0');
        lockEndMinuteInput.value = String(snappedEnd.getMinutes()).padStart(2, '0');

        if (!['00', '15', '30', '45'].includes(lockStartMinuteInput.value)) {
            lockStartMinuteInput.value = '00';
        }
        if (!['00', '15', '30', '45'].includes(lockEndMinuteInput.value)) {
            lockEndMinuteInput.value = '00';
        }

        syncLockRangeFromControls();
    }

    function setRepeatControlsEnabled(enabled) {
        eventRepeatModeInput.disabled = !enabled;
        eventRepeatUntilInput.disabled = !enabled;
    }

    function updateRepeatControls() {
        const mode = eventRepeatModeInput.value;
        const showUntilDate = mode === 'weekly_until_date' && !eventRepeatModeInput.disabled;

        eventRepeatUntilWrap.classList.toggle('d-none', !showUntilDate);

        if (eventRepeatModeInput.disabled) {
            eventRepeatHint.textContent = 'Opakování lze nastavit pouze při vytváření nové události.';
        } else if (mode === 'none') {
            eventRepeatHint.textContent = '';
        } else {
            eventRepeatHint.textContent = 'Vytvoří se samostatné události, které můžete později mazat po jedné nebo od určitého data dál.';
        }
    }

    function updateBillingControls() {
        const showBillingMonth = eventIsMakeupInput.checked && !eventIsLockInput.checked;
        eventBillingMonthWrap.classList.toggle('d-none', !showBillingMonth);

        if (!showBillingMonth && eventStartInput.value) {
            const baseDate = new Date(eventStartInput.value);
            if (!Number.isNaN(baseDate.getTime())) {
                eventBillingMonthInput.value = toMonthKey(baseDate);
            }
        }
    }

    function updateLockRepeatControls() {
        const mode = lockRepeatModeInput.value;
        const unlockMode = lockUnlockModeInput.checked;
        const showUntilDate = mode === 'weekly_until_date' && !unlockMode;

        lockRepeatUntilWrap.classList.toggle('d-none', !showUntilDate);
        lockRepeatModeInput.disabled = unlockMode;
        lockRepeatUntilInput.disabled = unlockMode;

        if (unlockMode) {
            lockRepeatHint.textContent = 'Odemknutí se provede jen pro zadaný interval.';
            lockRepeatUntilInput.value = '';
            return;
        }

        if (mode === 'none') {
            lockRepeatHint.textContent = '';
        } else {
            lockRepeatHint.textContent = 'Uzamčení se uloží po týdnech až do zvoleného termínu.';
        }
    }

    function updateModeUI() {
        const lockMode = eventIsLockInput.checked;

        eventTrainingFields.classList.toggle('d-none', lockMode);
        lockFields.classList.toggle('d-none', !lockMode);

        if (lockMode) {
            eventModalTitle.innerHTML = '<i class="fas fa-lock me-2 text-warning"></i>Uzamčení času';
            deleteEventBtn.innerHTML = '<i class="fas fa-trash me-1"></i>Smazat uzamčení';
        } else {
            eventModalTitle.innerHTML = '<i class="fas fa-calendar-plus me-2 text-warning"></i>Trénink';
            deleteEventBtn.innerHTML = '<i class="fas fa-trash me-1"></i>Smazat';
            lockUnlockModeInput.checked = false;
        }

        updateLockRepeatControls();
        updateBillingControls();
    }

    function renderDayPilotCalendar() {
        if (!window.DayPilot || typeof window.DayPilot.Calendar !== 'function' || !daypilotCalendarEl) {
            return false;
        }

        if (!dayPilotCalendar) {
            dayPilotCalendar = new DayPilot.Calendar('daypilotCalendar', {
                locale: 'cs-cz',
                viewType: 'Week',
                weekStarts: 1,
                cellDuration: 60,
                cellHeight: 68,
                eventArrangement: 'SideBySide',
                useEventBoxes: 'Never',
                showNonBusiness: false,
                businessWeekends: true,
                heightSpec: 'BusinessHoursNoScroll',
                businessBeginsHour: hourStart,
                businessEndsHour: hourEnd,
                eventMoveHandling: 'Disabled',
                eventResizeHandling: 'Disabled',
                eventDeleteHandling: 'Disabled',
                timeRangeSelectedHandling: 'JavaScript',
                onTimeRangeSelected: (args) => {
                    const rangeStart = dayPilotDateToJs(args.start);
                    const rangeEnd = dayPilotDateToJs(args.end);
                    dayPilotCalendar.clearSelection();

                    if (!rangeStart || !rangeEnd) {
                        return;
                    }

                    openEventModal(null, rangeStart);

                    if (isRangeLocked(rangeStart, rangeEnd)) {
                        eventIsLockInput.checked = true;
                        lockUnlockModeInput.checked = true;
                        setLockRangeControls(rangeStart, rangeEnd);
                        updateModeUI();
                    }
                },
                onEventClick: (args) => {
                    const clickedId = String(args.e.id());
                    if (clickedId.startsWith('lock-')) {
                        const lockId = Number(clickedId.replace('lock-', ''));
                        const lock = locks.find((item) => Number(item.id) === lockId);
                        if (lock) {
                            openEventModal(null, null, lock);
                        }
                        return;
                    }

                    const event = events.find((item) => String(item.id) === clickedId);
                    if (event) {
                        openEventModal(event);
                    }
                },
                onBeforeCellRender: (args) => {
                    const start = dayPilotDateToJs(args.cell.start);
                    const end = dayPilotDateToJs(args.cell.end);

                    if (start && end && isRangeLocked(start, end)) {
                        args.cell.backColor = '#e5e7eb';
                    }
                },
            });

            dayPilotCalendar.init();
        }

        dayPilotCalendar.update({
            startDate: toDateKey(currentWeekStart),
            events: [...locks.map(toDayPilotLockEvent), ...events.map(toDayPilotEvent)],
        });

        if (calendarGrid && calendarGrid.closest('.card')) {
            calendarGrid.closest('.card').classList.add('d-none');
        }
        if (daypilotCard) {
            daypilotCard.classList.remove('d-none');
        }

        return true;
    }

    function fromSqlDateTime(sqlDateTime) {
        return new Date(sqlDateTime.replace(' ', 'T'));
    }

    function formatDateCs(date) {
        return new Intl.DateTimeFormat('cs-CZ', { day: '2-digit', month: '2-digit', year: 'numeric' }).format(date);
    }

    function formatTimeCs(date) {
        return new Intl.DateTimeFormat('cs-CZ', { hour: '2-digit', minute: '2-digit' }).format(date);
    }

    function showError(el, text) {
        el.textContent = text;
        el.classList.remove('d-none');
    }

    function clearError(el) {
        el.textContent = '';
        el.classList.add('d-none');
    }

    function setWeekRangeLabel() {
        const end = addDays(currentWeekStart, 6);
        weekRangeLabel.textContent = `${formatDateCs(currentWeekStart)} - ${formatDateCs(end)}`;
    }

    function isSlotLocked(slotStart) {
        const slotEnd = new Date(slotStart);
        slotEnd.setHours(slotEnd.getHours() + 1);
        return locks.some((lock) => {
            const lockStart = fromSqlDateTime(lock.starts_at);
            const lockEnd = fromSqlDateTime(lock.ends_at);
            return lockStart < slotEnd && lockEnd > slotStart;
        });
    }

    function getEventsForSlot(slotStart) {
        const slotStartMs = slotStart.getTime();
        return events.filter((event) => {
            const start = fromSqlDateTime(event.starts_at);
            return start.getTime() === slotStartMs;
        });
    }

    function getEventTitle(event) {
        if (event.custom_title) {
            return event.custom_title;
        }
        if (event.athlete_id && event.first_name && event.last_name) {
            return `${event.last_name} ${event.first_name}`;
        }
        return 'Trénink';
    }

    function renderCalendar() {
        setWeekRangeLabel();

        if (renderDayPilotCalendar()) {
            return;
        }

        const dayDates = Array.from({ length: 7 }, (_, i) => addDays(currentWeekStart, i));

        const thead = document.createElement('thead');
        const headRow = document.createElement('tr');

        const headTime = document.createElement('th');
        headTime.className = 'time-col';
        headTime.textContent = 'Čas';
        headRow.appendChild(headTime);

        dayDates.forEach((dayDate, idx) => {
            const th = document.createElement('th');
            th.innerHTML = `<span class="day-name">${czechDayShort[idx]}</span><span class="day-date">${formatDateCs(dayDate)}</span>`;
            headRow.appendChild(th);
        });
        thead.appendChild(headRow);

        const tbody = document.createElement('tbody');

        for (let hour = hourStart; hour < hourEnd; hour++) {
            const tr = document.createElement('tr');

            const timeTd = document.createElement('td');
            timeTd.className = 'time-col';
            timeTd.textContent = `${String(hour).padStart(2, '0')}:00`;
            tr.appendChild(timeTd);

            dayDates.forEach((dayDate) => {
                const slotStart = new Date(dayDate);
                slotStart.setHours(hour, 0, 0, 0);

                const td = document.createElement('td');
                td.className = 'slot-cell';
                td.dataset.slot = toDateTimeInputValue(slotStart);

                const locked = isSlotLocked(slotStart);
                if (locked) {
                    td.classList.add('is-locked');
                }

                const slotEvents = getEventsForSlot(slotStart);
                if (slotEvents.length > 0) {
                    slotEvents.forEach((event) => {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'slot-event';
                        const color = getEventColorScheme(event);
                        const statusMeta = getEventStatusMeta(event);
                        btn.style.background = color.backColor;
                        btn.style.borderColor = color.barColor;
                        btn.style.color = color.fontColor;
                        if (statusMeta.className) {
                            btn.classList.add(statusMeta.className);
                        }
                        
                        const eventStart = fromSqlDateTime(event.starts_at);
                        const eventEnd = fromSqlDateTime(event.ends_at);
                        
                        // Formát času s minutami
                        const startTime = eventStart.toLocaleTimeString('cs-CZ', { hour: '2-digit', minute: '2-digit' });
                        const endTime = eventEnd.toLocaleTimeString('cs-CZ', { hour: '2-digit', minute: '2-digit' });
                        
                        const title = getEventTitle(event);
                        const statusBadge = statusMeta.label ? `<span class="badge bg-light text-dark ms-1">${statusMeta.label}</span>` : '';
                        const where = event.location ? `<span class="where"><i class="fas fa-location-dot me-1"></i>${event.location}</span>` : '';
                        btn.innerHTML = `<span class="time">${startTime}-${endTime}</span> ${title}${statusBadge}${where}`;
                        btn.addEventListener('click', (e) => {
                            e.stopPropagation();
                            openEventModal(event);
                        });
                        td.appendChild(btn);
                    });
                } else if (locked) {
                    const lockInfo = document.createElement('div');
                    lockInfo.className = 'slot-add-hint';
                    lockInfo.textContent = 'Uzamčeno';
                    td.appendChild(lockInfo);
                } else {
                    const hint = document.createElement('div');
                    hint.className = 'slot-add-hint';
                    hint.innerHTML = '<i class="fas fa-plus"></i>';
                    td.appendChild(hint);
                }

                td.addEventListener('click', () => {
                    if (locked) {
                        openEventModal(null, slotStart);
                        eventIsLockInput.checked = true;
                        lockUnlockModeInput.checked = true;
                        updateModeUI();
                        return;
                    }
                    openEventModal(null, slotStart);
                });

                tr.appendChild(td);
            });

            tbody.appendChild(tr);
        }

        calendarGrid.innerHTML = '';
        calendarGrid.appendChild(thead);
        calendarGrid.appendChild(tbody);
    }

    async function fetchJson(url, options = {}) {
        const response = await fetch(url, options);
        let payload;
        try {
            payload = await response.json();
        } catch (e) {
            payload = { success: false, error: 'Neplatná odpověď serveru' };
        }
        return payload;
    }

    async function loadWeekData() {
        const query = new URLSearchParams({ week_start: toDateKey(currentWeekStart) });
        const payload = await fetchJson(`<?= BASE_URL ?>/api/calendar_data.php?${query.toString()}`);
        if (!payload.success) {
            alert(payload.error || 'Nepodařilo se načíst kalendář');
            return;
        }

        events = payload.events || [];
        locks = payload.locks || [];
        renderCalendar();
    }

    function openEventModal(event = null, slotDate = null, lock = null) {
        clearError(eventError);
        requestInfo.textContent = '';
        requestInfo.classList.add('d-none');
        approveEventBtn.classList.add('d-none');
        activeEvent = event;

        if (lock) {
            eventIsLockInput.checked = true;
            eventIsLockInput.disabled = true;
            lockIdInput.value = String(lock.id);
            eventIdInput.value = '';

            lockUnlockModeInput.checked = false;
            lockNoteInlineInput.value = lock.note || '';
            setLockRangeControls(fromSqlDateTime(lock.starts_at), fromSqlDateTime(lock.ends_at));
            lockRepeatModeInput.value = 'none';
            lockRepeatUntilInput.value = '';

            deleteEventBtn.classList.remove('d-none');
        } else if (event) {
            eventIsLockInput.checked = false;
            eventIsLockInput.disabled = true;
            lockIdInput.value = '';
            eventIdInput.value = event.id;
            eventAthleteInput.value = event.athlete_id ? String(event.athlete_id) : '';
            eventCustomTitleInput.value = event.custom_title || '';
            eventLocationModeInput.value = event.location || 'custom';
            eventLocationInput.value = event.location || '';
            eventColorInput.value = normalizeColorKey(event.color_key);
            setEventStartControls(fromSqlDateTime(event.starts_at));
            eventRepeatModeInput.value = 'none';
            eventRepeatUntilInput.value = '';
            eventIsMakeupInput.checked = Number(event.is_makeup_session || 0) === 1;
            eventBillingMonthInput.value = event.billing_month ? String(event.billing_month).slice(0, 7) : toMonthKey(fromSqlDateTime(event.starts_at));
            setRepeatControlsEnabled(false);
            updateRepeatControls();
            deleteEventBtn.classList.remove('d-none');

            const isPendingRequest = (event.approval_status || 'approved') === 'pending' && Number(event.requested_by_athlete_id || 0) > 0;
            if (isPendingRequest) {
                requestInfo.textContent = 'Toto je nový požadavek sportovce. Můžete jej schválit, zamítnout nebo upravit. Uložení změn požadavek automaticky schválí.';
                requestInfo.classList.remove('d-none');
                approveEventBtn.classList.remove('d-none');
                deleteEventBtn.innerHTML = '<i class="fas fa-xmark me-1"></i>Zamítnout';
            } else {
                deleteEventBtn.innerHTML = '<i class="fas fa-trash me-1"></i>Smazat';
            }
        } else {
            eventIsLockInput.checked = false;
            eventIsLockInput.disabled = false;
            lockIdInput.value = '';
            eventIdInput.value = '';
            eventAthleteInput.value = '';
            eventCustomTitleInput.value = '';
            eventLocationModeInput.value = 'custom';
            eventLocationInput.value = '';
            eventColorInput.value = 'green';

            const base = slotDate ? new Date(slotDate) : new Date();
            base.setMinutes(0, 0, 0);
            if (!slotDate && base.getHours() < hourStart) {
                base.setHours(hourStart);
            }
            if (!slotDate && base.getHours() >= hourEnd) {
                base.setDate(base.getDate() + 1);
                base.setHours(hourStart);
            }
            setEventStartControls(base);

            const lockStart = new Date(base);
            lockStart.setMinutes(0, 0, 0);
            const lockEnd = new Date(lockStart);
            lockEnd.setHours(lockEnd.getHours() + 1);
            lockUnlockModeInput.checked = false;
            lockNoteInlineInput.value = '';
            setLockRangeControls(lockStart, lockEnd);
            lockRepeatModeInput.value = 'none';
            lockRepeatUntilInput.value = '';

            eventRepeatModeInput.value = 'none';
            eventRepeatUntilInput.value = '';
            eventIsMakeupInput.checked = false;
            eventBillingMonthInput.value = toMonthKey(base);
            setRepeatControlsEnabled(true);
            updateRepeatControls();
            deleteEventBtn.classList.add('d-none');
            deleteEventBtn.innerHTML = '<i class="fas fa-trash me-1"></i>Smazat';
        }

        updateModeUI();

        eventModal.show();
    }

    eventLocationModeInput.addEventListener('change', (e) => {
        if (e.target.value !== 'custom') {
            eventLocationInput.value = e.target.value;
        }
    });

    eventDateInput.addEventListener('change', syncEventStartFromControls);
    eventHourInput.addEventListener('change', syncEventStartFromControls);
    eventMinuteInput.addEventListener('change', syncEventStartFromControls);
    lockStartDateInput.addEventListener('change', syncLockRangeFromControls);
    lockStartHourInput.addEventListener('change', syncLockRangeFromControls);
    lockStartMinuteInput.addEventListener('change', syncLockRangeFromControls);
    lockEndDateInput.addEventListener('change', syncLockRangeFromControls);
    lockEndHourInput.addEventListener('change', syncLockRangeFromControls);
    lockEndMinuteInput.addEventListener('change', syncLockRangeFromControls);
    eventRepeatModeInput.addEventListener('change', updateRepeatControls);
    eventIsMakeupInput.addEventListener('change', updateBillingControls);
    eventIsLockInput.addEventListener('change', updateModeUI);
    lockUnlockModeInput.addEventListener('change', updateModeUI);
    lockRepeatModeInput.addEventListener('change', updateLockRepeatControls);

    eventForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        clearError(eventError);

        if (eventIsLockInput.checked) {
            syncLockRangeFromControls();
            const lockStartsAt = lockRangeStartInput.value;
            const lockEndsAt = lockRangeEndInput.value;
            const lockRepeatMode = lockUnlockModeInput.checked ? 'none' : lockRepeatModeInput.value;
            const lockRepeatUntil = lockRepeatUntilInput.value;

            if (!lockStartsAt || !lockEndsAt) {
                showError(eventError, 'Vyberte interval uzamčení od-do.');
                return;
            }

            if (!lockUnlockModeInput.checked && lockRepeatMode === 'weekly_until_date' && !lockRepeatUntil) {
                showError(eventError, 'Vyberte datum, do kterého se má uzamčení opakovat.');
                return;
            }

            const payload = await fetchJson('<?= BASE_URL ?>/api/calendar_save_lock.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    csrf_token: csrfToken,
                    lock_id: lockIdInput.value ? Number(lockIdInput.value) : 0,
                    starts_at: lockStartsAt,
                    ends_at: lockEndsAt,
                    note: lockNoteInlineInput.value.trim(),
                    mode: lockUnlockModeInput.checked ? 'unlock' : 'lock',
                    repeat_mode: lockRepeatMode,
                    repeat_until: lockRepeatUntil,
                }),
            });

            if (!payload.success) {
                showError(eventError, payload.error || 'Uložení uzamčení se nepodařilo.');
                return;
            }

            eventModal.hide();
            await loadWeekData();
            return;
        }

        syncEventStartFromControls();

        const startsAt = eventStartInput.value;
        const athleteId = eventAthleteInput.value ? Number(eventAthleteInput.value) : 0;
        const customTitle = eventCustomTitleInput.value.trim();
        const repeatMode = eventRepeatModeInput.disabled ? 'none' : eventRepeatModeInput.value;
        const repeatUntil = eventRepeatUntilInput.value;
        const isMakeupSession = eventIsMakeupInput.checked;
        const billingMonth = eventBillingMonthInput.value;

        if (!startsAt) {
            showError(eventError, 'Vyberte datum a čas začátku tréninku.');
            return;
        }

        if (repeatMode === 'weekly_until_date' && !repeatUntil) {
            showError(eventError, 'Vyberte datum, do kterého se má trénink opakovat.');
            return;
        }

        if (isMakeupSession && !billingMonth) {
            showError(eventError, 'Vyberte hrazený měsíc pro náhradní termín.');
            return;
        }

        if (!athleteId && !customTitle) {
            showError(eventError, 'Vyberte sportovce nebo vyplňte vlastní název.');
            return;
        }

        const payload = await fetchJson('<?= BASE_URL ?>/api/calendar_save_event.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                csrf_token: csrfToken,
                event_id: eventIdInput.value ? Number(eventIdInput.value) : 0,
                athlete_id: athleteId,
                custom_title: customTitle,
                location: eventLocationInput.value.trim(),
                color_key: normalizeColorKey(eventColorInput.value),
                starts_at: startsAt,
                is_makeup_session: isMakeupSession,
                billing_month: billingMonth,
                repeat_mode: repeatMode,
                repeat_until: repeatUntil,
            }),
        });

        if (!payload.success) {
            showError(eventError, payload.error || 'Uložení se nepodařilo.');
            return;
        }

        eventModal.hide();
        await loadWeekData();
    });

    approveEventBtn.addEventListener('click', async () => {
        const eventId = Number(eventIdInput.value || 0);
        if (!eventId) {
            return;
        }

        syncEventStartFromControls();

        const payload = await fetchJson('<?= BASE_URL ?>/api/calendar_save_event.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                csrf_token: csrfToken,
                event_id: eventId,
                athlete_id: eventAthleteInput.value ? Number(eventAthleteInput.value) : 0,
                custom_title: eventCustomTitleInput.value.trim(),
                location: eventLocationInput.value.trim(),
                color_key: normalizeColorKey(eventColorInput.value),
                starts_at: eventStartInput.value,
                is_makeup_session: eventIsMakeupInput.checked,
                billing_month: eventBillingMonthInput.value,
                repeat_mode: 'none',
                repeat_until: '',
                approval_action: 'approve',
            }),
        });

        if (!payload.success) {
            showError(eventError, payload.error || 'Schválení se nepodařilo.');
            return;
        }

        eventModal.hide();
        await loadWeekData();
    });

    deleteEventBtn.addEventListener('click', async () => {
        if (eventIsLockInput.checked) {
            const lockId = Number(lockIdInput.value || 0);
            if (!lockId) {
                return;
            }

            if (!confirm('Opravdu chcete toto uzamčení smazat?')) {
                return;
            }

            const payload = await fetchJson('<?= BASE_URL ?>/api/calendar_delete_lock.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    csrf_token: csrfToken,
                    lock_id: lockId,
                }),
            });

            if (!payload.success) {
                showError(eventError, payload.error || 'Smazání uzamčení se nepodařilo.');
                return;
            }

            eventModal.hide();
            await loadWeekData();
            return;
        }

        const eventId = Number(eventIdInput.value || 0);
        if (!eventId) {
            return;
        }

        let deleteScope = 'single';
        if (activeEvent && activeEvent.series_id) {
            const deleteFuture = confirm('Smazat tento trénink i všechny budoucí ve stejné sérii?\n\nOK = Ano (tento + budoucí)\nStorno = Vybrat jen tento');
            if (deleteFuture) {
                deleteScope = 'future';
            } else {
                const deleteSingle = confirm('Smazat jen tento trénink?');
                if (!deleteSingle) {
                    return;
                }
            }
        } else {
            if (!confirm('Opravdu chcete tento trénink smazat?')) {
                return;
            }
        }

        const payload = await fetchJson('<?= BASE_URL ?>/api/calendar_delete_event.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                csrf_token: csrfToken,
                event_id: eventId,
                delete_scope: deleteScope,
            }),
        });

        if (!payload.success) {
            showError(eventError, payload.error || 'Smazání se nepodařilo.');
            return;
        }

        if (payload.message && Number(payload.paid_affected_count || 0) > 0) {
            alert(payload.message);
        }

        eventModal.hide();
        await loadWeekData();
    });

    document.getElementById('prevWeekBtn').addEventListener('click', async () => {
        currentWeekStart = addDays(currentWeekStart, -7);
        await loadWeekData();
    });

    document.getElementById('nextWeekBtn').addEventListener('click', async () => {
        currentWeekStart = addDays(currentWeekStart, 7);
        await loadWeekData();
    });

    document.getElementById('todayWeekBtn').addEventListener('click', async () => {
        currentWeekStart = getMonday(new Date());
        await loadWeekData();
    });

    document.getElementById('quickAddBtn').addEventListener('click', () => {
        openEventModal();
    });

    populateEventHourOptions();
    populateLockHourOptions(lockStartHourInput);
    populateLockHourOptions(lockEndHourInput);
    updateModeUI();

    loadWeekData();
});
</script>

<?php renderFooter();
