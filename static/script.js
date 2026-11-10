(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        const btn = event.target.closest('.hister-send-btn');
        if (!btn) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        if (btn.classList.contains('loading') || btn.classList.contains('success')) {
            return;
        }

        const originalText = btn.textContent;
        const actionUrl = btn.getAttribute('data-action');
        const entryId = btn.getAttribute('data-id');
        const csrfToken = btn.getAttribute('data-csrf');

        const msgSending = btn.getAttribute('data-i18n-sending') || '⏳ Envoi...';
        const msgSuccess = btn.getAttribute('data-i18n-success') || '✔ Transmis à Hister';
        const msgQueued  = btn.getAttribute('data-i18n-queued')  || '⌛ En file d\'attente';
        const msgFailed  = btn.getAttribute('data-i18n-failed')  || '✖ Échec de transmission';
        const msgNetwork = btn.getAttribute('data-i18n-network') || '✖ Erreur réseau';

        btn.classList.add('loading');
        btn.textContent = msgSending;

        const bodyData = new URLSearchParams({
            _csrf: csrfToken,
            id: entryId
        });

        fetch(actionUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: bodyData.toString()
        })
        .then(function (res) {
            return res.json().catch(function () {
                return { status: res.ok ? 'ok' : 'error' };
            });
        })
        .then(function (data) {
            btn.classList.remove('loading');

            if (data.status === 'ok') {
                btn.classList.add('success');
                btn.textContent = msgSuccess;
            } else if (data.status === 'queued') {
                btn.classList.add('queued');
                btn.textContent = msgQueued;
            } else {
                btn.classList.add('failed');
                btn.textContent = msgFailed;
                setTimeout(function () {
                    btn.classList.remove('failed');
                    btn.textContent = originalText;
                }, 3000);
            }
        })
        .catch(function () {
            btn.classList.remove('loading');
            btn.classList.add('failed');
            btn.textContent = msgNetwork;
            setTimeout(function () {
                btn.classList.remove('failed');
                btn.textContent = originalText;
            }, 3000);
        });
    });
})();
