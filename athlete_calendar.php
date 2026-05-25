<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/athlete_header.php';

requireAthleteLogin();

$athlete = getCurrentAthlete();
$venues = array_values(array_filter(getTrainingVenues(), fn($row) => !empty($row['name'])));
renderAthleteHeader('Muj kalendar');
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

#daypilotCalendar .coach-calendar-pending {
    animation: athletePendingPulse .72s ease-in-out infinite alternate;
}

@keyframes athletePendingPulse {
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
    #daypilotCalendar .coach-calendar-pending {
        animation: none;
    }
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
}
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h2 class="mb-0"><i class="fas fa-calendar-alt me-2 text-warning"></i>Kalendář</h2>
    <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-outline-secondary btn-sm" id="prevWeekBtn">
            <i class="fas fa-chevron-left me-1"></i>Předchozí týden
        </button>
        <button class="btn btn-outline-dark btn-sm" id="todayWeekBtn">Tento týden</button>
        <button class="btn btn-outline-secondary btn-sm" id="nextWeekBtn">
            Další týden<i class="fas fa-chevron-right ms-1"></i>
        </button>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <div class="fw-bold" id="weekRangeLabel">Načítám týden...</div>
            <div class="text-muted small">Klikněte do volného slotu pro vytvoření rezervace.</div>
        </div>
        <div class="d-flex align-items-center gap-2 small flex-wrap">
            <span class="badge" style="background:#16a34a;color:#fff">Schváleno</span>
            <span class="badge" style="background:#f97316;color:#fff">Ke schválení</span>
            <span class="badge" style="background:#374151;color:#fff">Obsazeno</span>
            <span class="badge text-bg-secondary">Uzamčeno</span>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm" id="daypilotCard">
    <div class="card-body p-2 p-md-3">
        <div id="daypilotCalendar"></div>
    </div>
</div>

<div class="modal fade" id="reserveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="reserveForm">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title"><i class="fas fa-calendar-plus me-2 text-warning"></i>Rezervovat termín</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="reserveStart" value="">

                    <div class="mb-2">
                        <label class="form-label fw-semibold">Začátek</label>
                        <input type="text" class="form-control" id="reserveStartLabel" disabled>
                    </div>

                    <div class="mb-2">
                        <label class="form-label fw-semibold">Název</label>
                        <input type="text" id="reserveTitle" class="form-control" maxlength="140" placeholder="Např. kondiční trénink">
                    </div>

                    <div>
                        <label class="form-label fw-semibold">Místo</label>
                        <div class="input-group">
                            <select id="reserveLocationMode" class="form-select" style="flex: 0 0 180px">
                                <option value="custom">Napsat sám</option>
                                <?php foreach ($venues as $venue): ?>
                                <option value="<?= h((string)$venue['name']) ?>"
                                        data-address="<?= h((string)($venue['address'] ?? '')) ?>"
                                        data-note="<?= h((string)($venue['note'] ?? '')) ?>">
                                    <?= h((string)$venue['name']) ?>
                                    <?php if (!empty($venue['address'])): ?>
                                    - <?= h((string)$venue['address']) ?>
                                    <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" id="reserveLocation" class="form-control" maxlength="255" placeholder="Např. Stadion">
                        </div>
                        <div class="small text-muted mt-1" id="reserveLocationHint"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zrušit</button>
                    <button type="submit" class="btn btn-warning fw-bold">Rezervovat</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://unpkg.com/@daypilot/daypilot-lite-javascript@5.6.0/daypilot-javascript.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const csrfToken = <?= json_encode(csrfToken(), JSON_UNESCAPED_UNICODE) ?>;
    const weekRangeLabel = document.getElementById('weekRangeLabel');
    const daypilotCalendarEl = document.getElementById('daypilotCalendar');
    const reserveModalEl = document.getElementById('reserveModal');
    const reserveModal = new bootstrap.Modal(reserveModalEl);
    const reserveLocationModeInput = document.getElementById('reserveLocationMode');
    const reserveLocationInput = document.getElementById('reserveLocation');
    const reserveLocationHint = document.getElementById('reserveLocationHint');

    let currentWeekStart = getMonday(new Date());
    let events = [];
    let locks = [];
    let dayPilotCalendar = null;
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

    function normalizeColorKey(colorKey) {
        if (typeof colorKey !== 'string') {
            return 'blue';
        }
        return Object.prototype.hasOwnProperty.call(eventColorSchemes, colorKey) ? colorKey : 'blue';
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

    function getEventTitle(event) {
        if (event.athlete_id && event.first_name && event.last_name) {
            return `${event.last_name} ${event.first_name}`;
        }
        return event.custom_title || 'Trénink';
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

    function toDateTimeInputValue(date) {
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const d = String(date.getDate()).padStart(2, '0');
        const h = String(date.getHours()).padStart(2, '0');
        const min = String(date.getMinutes()).padStart(2, '0');
        return `${y}-${m}-${d}T${h}:${min}`;
    }

    function toDateTimeSecondsValue(date) {
        return `${toDateTimeInputValue(date)}:00`;
    }

    function fromSqlDateTime(value) {
        return new Date(String(value).replace(' ', 'T'));
    }

    function dayPilotDateToJs(value) {
        if (!value) return null;
        const raw = typeof value.toString === 'function' ? value.toString() : String(value);
        return new Date(raw.replace(' ', 'T'));
    }

    function formatDateCs(date) {
        return `${String(date.getDate()).padStart(2, '0')}.${String(date.getMonth() + 1).padStart(2, '0')}.${date.getFullYear()}`;
    }

    function formatTimeCs(date) {
        return `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
    }

    function getWeekRangeLabel() {
        const start = new Date(currentWeekStart);
        const end = addDays(start, 6);
        return `${formatDateCs(start)} - ${formatDateCs(end)}`;
    }

    function isRangeLocked(start, end) {
        return locks.some((lock) => {
            const lockStart = fromSqlDateTime(lock.starts_at);
            const lockEnd = fromSqlDateTime(lock.ends_at);
            return lockStart < end && lockEnd > start;
        });
    }

    function isRangeOccupied(start, end) {
        return events.some((event) => {
            const eventStart = fromSqlDateTime(event.starts_at);
            const eventEnd = fromSqlDateTime(event.ends_at);
            return eventStart < end && eventEnd > start;
        });
    }

    function toDayPilotEvent(event) {
        const startDate = fromSqlDateTime(event.starts_at);
        const endDate = fromSqlDateTime(event.ends_at);
        const title = getEventTitle(event);
        const timeLabel = `${formatTimeCs(startDate)} - ${formatTimeCs(endDate)}`;
        const placeLabel = event.location ? `${event.location}` : '';
        const detailLine = placeLabel ? `${timeLabel} | ${placeLabel}` : timeLabel;
        const place = event.location ? `\nMísto: ${event.location}` : '';
        const time = `\nČas: ${formatTimeCs(startDate)} - ${formatTimeCs(endDate)}`;
        const color = getEventColorScheme(event);
        const statusMeta = getEventStatusMeta(event);
        const statusLine = statusMeta.label ? `\nStav: ${statusMeta.label}` : '';
        const mine = !!event.is_mine || !!event.is_requested_by_me;

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
            moveDisabled: true,
            resizeDisabled: true,
            clickDisabled: !mine,
            data: {
                mine,
            },
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
            clickDisabled: true,
        };
    }

    function updateReserveLocationHint() {
        const selectedOption = reserveLocationModeInput.options[reserveLocationModeInput.selectedIndex] || null;

        if (!selectedOption || reserveLocationModeInput.value === 'custom') {
            reserveLocationHint.textContent = 'Vyberte existující místo, nebo zadejte vlastní.';
            return;
        }

        const address = selectedOption.dataset.address || '';
        const note = selectedOption.dataset.note || '';
        const parts = [];

        if (address) parts.push(address);
        if (note) parts.push(note);

        reserveLocationHint.textContent = parts.length ? parts.join(' • ') : 'Místo je načtené z katalogu training_venues.';
    }

    function openReserveModal(startDate) {
        document.getElementById('reserveStart').value = toDateTimeInputValue(startDate);
        document.getElementById('reserveStartLabel').value = `${formatDateCs(startDate)} ${formatTimeCs(startDate)}`;
        document.getElementById('reserveTitle').value = '';
        reserveLocationModeInput.value = 'custom';
        reserveLocationInput.value = '';
        updateReserveLocationHint();
        reserveModal.show();
    }

    async function cancelMyEvent(eventId) {
        const response = await fetch('<?= BASE_URL ?>/api/athlete_calendar_delete_event.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: csrfToken,
                event_id: Number(eventId),
            }),
        });
        const payload = await response.json();
        if (!payload.success) {
            alert(payload.error || 'Termín se nepodařilo zrušit.');
            return;
        }
        await loadWeekData();
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
                startDate: toDateKey(currentWeekStart),
                cellDuration: 60,
                cellHeight: 54,
                eventArrangement: 'SideBySide',
                useEventBoxes: 'Never',
                showNonBusiness: false,
                businessWeekends: true,
                heightSpec: 'BusinessHoursNoScroll',
                businessBeginsHour: hourStart,
                businessEndsHour: hourEnd,
                durationBarVisible: true,
                eventMoveHandling: 'Disabled',
                eventResizeHandling: 'Disabled',
                eventDeleteHandling: 'Disabled',
                timeRangeSelectedHandling: 'JavaScript',
                onTimeRangeSelected: (args) => {
                    const start = dayPilotDateToJs(args.start);
                    const end = dayPilotDateToJs(args.end);
                    dayPilotCalendar.clearSelection();

                    if (!start || !end) return;
                    if (isRangeLocked(start, end)) {
                        alert('Vybraný čas je uzamčený.');
                        return;
                    }
                    if (isRangeOccupied(start, end)) {
                        alert('Vybraný čas je obsazený.');
                        return;
                    }

                    openReserveModal(start);
                },
                onEventClick: async (args) => {
                    const data = args.e.data || {};
                    if (!data.mine) return;
                    const ok = confirm('Opravdu chcete zrušit tento termín?');
                    if (!ok) return;
                    await cancelMyEvent(args.e.id());
                },
            });

            dayPilotCalendar.init();
        }

        dayPilotCalendar.update({
            startDate: toDateKey(currentWeekStart),
            events: [...locks.map(toDayPilotLockEvent), ...events.map(toDayPilotEvent)],
        });

        return true;
    }

    async function loadWeekData() {
        weekRangeLabel.textContent = getWeekRangeLabel();
        const params = new URLSearchParams({ week_start: toDateKey(currentWeekStart) });
        const response = await fetch(`<?= BASE_URL ?>/api/athlete_calendar_data.php?${params.toString()}`, {
            credentials: 'same-origin',
        });
        const payload = await response.json();

        if (!payload.success) {
            alert(payload.error || 'Nepodařilo se načíst kalendář.');
            return;
        }

        events = payload.events || [];
        locks = payload.locks || [];

        if (!renderDayPilotCalendar()) {
            alert('Nepodařilo se inicializovat zobrazení kalendáře.');
        }
    }

    reserveLocationModeInput.addEventListener('change', () => {
        const value = reserveLocationModeInput.value;
        if (value !== 'custom') {
            reserveLocationInput.value = value;
        }
        updateReserveLocationHint();
    });

    document.getElementById('reserveForm').addEventListener('submit', async (event) => {
        event.preventDefault();

        const payload = {
            csrf_token: csrfToken,
            starts_at: document.getElementById('reserveStart').value,
            custom_title: document.getElementById('reserveTitle').value.trim(),
            location: reserveLocationInput.value.trim(),
        };

        const response = await fetch('<?= BASE_URL ?>/api/athlete_calendar_save_event.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });

        const result = await response.json();
        if (!result.success) {
            alert(result.error || 'Nepodařilo se vytvořit rezervaci.');
            return;
        }

        reserveModal.hide();
        await loadWeekData();
    });

    document.getElementById('prevWeekBtn').addEventListener('click', () => {
        currentWeekStart = addDays(currentWeekStart, -7);
        loadWeekData();
    });

    document.getElementById('nextWeekBtn').addEventListener('click', () => {
        currentWeekStart = addDays(currentWeekStart, 7);
        loadWeekData();
    });

    document.getElementById('todayWeekBtn').addEventListener('click', () => {
        currentWeekStart = getMonday(new Date());
        loadWeekData();
    });

    updateReserveLocationHint();
    loadWeekData();
});
</script>

<?php renderAthleteFooter();
