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
        btn.classList.add('loading');
        btn.textContent = '⏳ Envoi...';

        fetch(btn.href, {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
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
                btn.textContent = '✔ Transmis à Hister';
            } else if (data.status === 'queued') {
                btn.classList.add('queued');
                btn.textContent = '⌛ En file d\'attente';
            } else {
                btn.classList.add('failed');
                btn.textContent = '✖ Échec de transmission';
                setTimeout(function () {
                    btn.classList.remove('failed');
                    btn.textContent = originalText;
                }, 3000);
            }
        })
        .catch(function () {
            btn.classList.remove('loading');
            btn.classList.add('failed');
            btn.textContent = '✖ Erreur réseau';
            setTimeout(function () {
                btn.classList.remove('failed');
                btn.textContent = originalText;
            }, 3000);
        });
    });
})();