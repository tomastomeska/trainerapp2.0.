<?php

function renderSupportWidget(string $userType = 'coach'): void {
    $userType = $userType === 'athlete' ? 'athlete' : 'coach';
    $csrf = csrfToken();
    $apiUrl = BASE_URL . '/api/support_ticket_create.php';
    ?>
<div id="supportWidgetRoot">
    <button type="button" class="support-fab" id="supportFab" title="Nahlásit problém">
        <i class="fas fa-question"></i>
    </button>

    <div class="modal fade" id="supportModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-life-ring me-2 text-primary"></i>Kontaktovat podporu
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zavřít"></button>
                </div>
                <form id="supportForm" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="alert d-none" id="supportAlert" role="alert"></div>

                        <div class="mb-3">
                            <label for="supportSubject" class="form-label fw-semibold">Předmět</label>
                            <input type="text" class="form-control" id="supportSubject" name="subject" maxlength="255" required>
                        </div>

                        <div class="mb-3">
                            <label for="supportIssueType" class="form-label fw-semibold">O jaký problém jde?</label>
                            <select class="form-select" id="supportIssueType" name="issue_type" required>
                                <option value="" selected disabled>Vyberte typ problému</option>
                                <option value="Technický problém">Technický problém</option>
                                <option value="Nejasné chování aplikace">Nejasné chování aplikace</option>
                                <option value="Chyba v datech">Chyba v datech</option>
                                <option value="Platby">Platby</option>
                                <option value="Jiné">Jiné</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="supportDescription" class="form-label fw-semibold">Popis problému</label>
                            <textarea class="form-control" id="supportDescription" name="description" rows="5" maxlength="5000" required></textarea>
                        </div>

                        <div>
                            <label for="supportScreenshot" class="form-label fw-semibold">Screenshot (volitelné)</label>
                            <input type="file" class="form-control" id="supportScreenshot" name="screenshot" accept="image/jpeg,image/png,image/gif,image/webp">
                            <div class="form-text">Maximální velikost je 8 MB.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zrušit</button>
                        <button type="submit" class="btn btn-primary" id="supportSubmitBtn">
                            <i class="fas fa-paper-plane me-1"></i>Odeslat
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
.support-fab {
    position: fixed;
    right: 18px;
    bottom: 18px;
    width: 54px;
    height: 54px;
    border: none;
    border-radius: 50%;
    background: linear-gradient(135deg, #0d6efd, #0b5ed7);
    color: #fff;
    font-size: 22px;
    box-shadow: 0 10px 24px rgba(13, 110, 253, 0.35);
    z-index: 1080;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: transform .15s ease, box-shadow .2s ease;
}
.support-fab:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 28px rgba(13, 110, 253, 0.4);
}
.support-fab:active {
    transform: translateY(0);
}
@media (max-width: 768px) {
    .support-fab {
        right: 12px;
        bottom: 12px;
        width: 50px;
        height: 50px;
        font-size: 20px;
    }
}
</style>

<script>
(function () {
    const supportFab = document.getElementById('supportFab');
    const supportModalEl = document.getElementById('supportModal');
    const supportForm = document.getElementById('supportForm');
    const supportAlert = document.getElementById('supportAlert');
    const supportSubmitBtn = document.getElementById('supportSubmitBtn');

    if (!supportFab || !supportModalEl || !supportForm || !supportAlert || !supportSubmitBtn) {
        return;
    }

    const supportModal = new bootstrap.Modal(supportModalEl);

    supportFab.addEventListener('click', function () {
        supportAlert.className = 'alert d-none';
        supportAlert.textContent = '';
        supportModal.show();
    });

    supportForm.addEventListener('submit', async function (e) {
        e.preventDefault();

        supportSubmitBtn.disabled = true;
        const originalHtml = supportSubmitBtn.innerHTML;
        supportSubmitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Odesílám...';

        const formData = new FormData(supportForm);
        formData.append('csrf_token', <?= json_encode($csrf) ?>);
        formData.append('page_url', window.location.href);
        formData.append('portal', <?= json_encode($userType) ?>);

        try {
            const response = await fetch(<?= json_encode($apiUrl) ?>, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });

            const data = await response.json().catch(() => ({ ok: false, error: 'Neočekávaná odpověď serveru.' }));

            if (!response.ok || !data.ok) {
                throw new Error(data.error || 'Odeslání ticketu se nepodařilo.');
            }

            supportAlert.className = 'alert alert-success';
            supportAlert.textContent = 'Děkujeme, požadavek na podporu byl odeslán.';
            supportForm.reset();

            setTimeout(function () {
                supportModal.hide();
            }, 1200);
        } catch (err) {
            supportAlert.className = 'alert alert-danger';
            supportAlert.textContent = err && err.message ? err.message : 'Odeslání ticketu se nepodařilo.';
        } finally {
            supportSubmitBtn.disabled = false;
            supportSubmitBtn.innerHTML = originalHtml;
        }
    });
})();
</script>
<?php
}
