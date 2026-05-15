/* assets/js/app.js – Globální JavaScript */

// Sdílený autosave helper pro sportovní formuláře.
window.createSportAutosave = function createSportAutosave(options) {
    const form = options.form;
    const statusEl = options.statusEl || null;
    const endpoint = options.endpoint;
    const buildPayload = options.buildPayload;
    const debounceMs = typeof options.debounceMs === 'number' ? options.debounceMs : 700;

    if (!form || !endpoint || typeof buildPayload !== 'function') {
        return null;
    }

    let timer = null;
    let saving = false;
    let queued = false;
    let lastSavedHash = '';

    function setStatus(text, cls) {
        if (!statusEl) {
            return;
        }
        statusEl.classList.remove('text-muted', 'text-success', 'text-danger');
        statusEl.classList.add(cls || 'text-muted');
        statusEl.textContent = text;
    }

    async function saveNow(force) {
        const payload = buildPayload();
        const hash = JSON.stringify(payload);

        if (!force && hash === lastSavedHash) {
            return;
        }

        if (saving) {
            queued = true;
            return;
        }

        saving = true;
        setStatus('Ukladam...', 'text-muted');

        try {
            const resp = await fetch(endpoint, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(payload),
                credentials: 'same-origin'
            });
            const data = await resp.json();
            if (!data.success) {
                throw new Error(data.error || 'Autosave chyba');
            }
            lastSavedHash = hash;
            setStatus('Ulozeno ' + (data.saved_at || ''), 'text-success');
        } catch (e) {
            setStatus('Neulozeno', 'text-danger');
        } finally {
            saving = false;
            if (queued) {
                queued = false;
                saveNow(false);
            }
        }
    }

    function scheduleSave() {
        setStatus('Neulozene zmeny', 'text-muted');
        if (timer) {
            clearTimeout(timer);
        }
        timer = setTimeout(function() {
            saveNow(false);
        }, debounceMs);
    }

    form.addEventListener('input', function(e) {
        if (!e.target || e.target.type === 'hidden') {
            return;
        }
        scheduleSave();
    });

    form.addEventListener('change', function(e) {
        if (!e.target || e.target.type === 'hidden') {
            return;
        }
        scheduleSave();
    });

    return {
        scheduleSave,
        saveNow: function() { return saveNow(true); }
    };
};

// Auto-zavírání flash alertů po 5 sekundách
document.addEventListener('DOMContentLoaded', function () {
    const alerts = document.querySelectorAll('.alert.alert-dismissible');
    alerts.forEach(function (alert) {
        setTimeout(function () {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
            if (bsAlert) bsAlert.close();
        }, 5000);
    });

    // Aktivní nav-link
    const currentPath = window.location.pathname;
    document.querySelectorAll('.navbar-nav .nav-link').forEach(function (link) {
        if (link.getAttribute('href') === currentPath) {
            link.classList.add('active');
        }
    });
});
