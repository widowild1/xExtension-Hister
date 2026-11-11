(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const box = document.getElementById('hister-drain-box');
        if (!box) return;

        const statusUrl = box.getAttribute('data-status-url');
        const progressWrapper = document.getElementById('drain-progress-wrapper');
        const startWrapper = document.getElementById('drain-start-wrapper');
        const bar = document.getElementById('drain-bar');
        const statusText = document.getElementById('drain-status-text');
        const detailText = document.getElementById('drain-detail-text');
        const queueSizeText = document.getElementById('queue-size-text');

        let interval = null;

        function updateUI(d) {
            if (queueSizeText && d.queue_size !== undefined) {
                queueSizeText.textContent = d.queue_size + ' article(s) en file d\'attente';
            }

            if (d.running) {
                if (progressWrapper) progressWrapper.style.display = 'block';
                if (startWrapper) startWrapper.style.display = 'none';

                const pct = d.pct !== undefined ? d.pct : 0;
                if (bar) {
                    bar.style.width = Math.max(2, Math.min(100, pct)) + '%';
                    bar.textContent = pct + '%';
                }
                if (statusText) {
                    statusText.textContent = '⏳ En cours : ' + (d.processed || 0) + ' / ' + (d.total || 0) +
                        ' articles (' + pct + '%) — ' + (d.rate || 0) + ' art/s — Reste ~' + (d.eta_min || 0) + ' min';
                }
                if (detailText) {
                    detailText.innerHTML = '(+' + (d.sent || 0) + ' indexés | ' + (d.conflict || 0) + ' vus) — <em>Actualisation en direct sans rechargement</em>';
                }
            } else {
                if (d.pct === 100) {
                    if (bar) {
                        bar.style.width = '100%';
                        bar.textContent = '100%';
                    }
                    if (statusText) {
                        statusText.textContent = '✅ Dépilage terminé avec succès ! (' + (d.total || 0) + ' articles traités)';
                    }
                }
                if (interval && !d.running) {
                    clearInterval(interval);
                    interval = null;
                }
            }
        }

        function poll() {
            fetch(statusUrl, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (res) { return res.json(); })
            .then(updateUI)
            .catch(function () {});
        }

        poll();
        interval = setInterval(poll, 2500);
    });
})();
