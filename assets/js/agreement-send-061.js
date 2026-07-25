(() => {
    'use strict';

    const cfg = window.BCSAgreementSend061 || null;
    if (!cfg || !cfg.ajaxUrl || !cfg.nonce) return;

    let closeTimer = 0;
    let reloadTimer = 0;

    const normalize = (value) => String(value || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/\s+/g, ' ')
        .trim();

    const ensurePopup = () => {
        let popup = document.getElementById('bcs-result-popup-061');
        if (popup) return popup;

        popup = document.createElement('div');
        popup.id = 'bcs-result-popup-061';
        popup.className = 'bcs-result-popup-061';
        popup.setAttribute('role', 'status');
        popup.setAttribute('aria-live', 'polite');
        popup.setAttribute('aria-atomic', 'true');
        popup.innerHTML = '<div class="bcs-result-popup-061__box"><div class="bcs-result-popup-061__icon" aria-hidden="true">✓</div><p class="bcs-result-popup-061__message"></p><div class="bcs-result-popup-061__progress" aria-hidden="true"><span></span></div></div>';
        (document.body || document.documentElement).appendChild(popup);
        return popup;
    };

    const notify = (message, ok = true, duration = Number(cfg.duration) || 2000) => {
        const popup = ensurePopup();
        const icon = popup.querySelector('.bcs-result-popup-061__icon');
        const text = popup.querySelector('.bcs-result-popup-061__message');
        const progress = popup.querySelector('.bcs-result-popup-061__progress');

        window.clearTimeout(closeTimer);
        popup.className = 'bcs-result-popup-061 ' + (ok ? 'is-success' : 'is-error');
        if (icon) icon.textContent = ok ? '✓' : '×';
        if (text) text.textContent = String(message || (ok ? cfg.successFallback : cfg.errorFallback));
        if (progress) {
            const replacement = progress.cloneNode(true);
            progress.replaceWith(replacement);
            const bar = replacement.querySelector('span');
            if (bar) bar.style.animationDuration = duration + 'ms';
        }

        window.requestAnimationFrame(() => popup.classList.add('is-visible'));
        closeTimer = window.setTimeout(() => popup.classList.remove('is-visible'), duration);
        return duration;
    };

    window.bcsNotify061 = notify;
    window.bcsNotify = notify;

    // Na stronie CRM eliminujemy natywne alerty pozostawione przez historyczne wersje.
    // Typ komunikatu jest rozpoznawany po treści, dzięki czemu starsze błędy nadal
    // są prezentowane jako czerwony komunikat, ale bez blokowania przeglądarki.
    window.__bcsNativeAlert061 = window.alert.bind(window);
    window.alert = (message) => {
        const normalized = normalize(message);
        const isError = /nie udalo|blad|nieprawid|wygasl|brak uprawnien|nie mozna|anulowan/.test(normalized);
        notify(message, !isError);
    };

    const registrationId = (element) => {
        const candidates = [];
        const push = (value) => {
            if (value !== undefined && value !== null && String(value).trim() !== '') candidates.push(String(value));
        };

        if (element?.dataset) {
            push(element.dataset.registrationId);
            push(element.dataset.id);
        }

        const host = element?.closest?.('tr[data-id],tr[data-registration-id],[data-registration-id],[data-id],form');
        if (host) {
            push(host.dataset?.registrationId);
            push(host.dataset?.id);
            ['registration_id', 'id'].forEach((name) => push(host.querySelector?.('[name="' + name + '"]')?.value));
        }

        const href = element?.getAttribute?.('href');
        if (href) {
            try {
                const url = new URL(href, window.location.href);
                push(url.searchParams.get('registration_id'));
                push(url.searchParams.get('view'));
                push(url.searchParams.get('id'));
            } catch (error) {}
        }

        const current = new URL(window.location.href);
        push(current.searchParams.get('view'));
        push(current.searchParams.get('registration_id'));
        push(current.searchParams.get('id'));

        const valid = candidates.find((value) => /^\d+$/.test(value));
        return valid ? Number(valid) : 0;
    };

    const isSendAgreement = (element) => {
        if (!element || element.classList.contains('bcs-org-sign-046')) return false;
        const text = normalize(element.innerText || element.value || element.textContent);
        if (text.includes('przypomnij')) return false;

        const form = element.closest?.('form');
        const actionValues = [
            form?.querySelector?.('[name="workflow"]')?.value,
            form?.querySelector?.('[name="quick_action"]')?.value,
            form?.querySelector?.('[name="card_action"]')?.value,
            element.name === 'workflow' ? element.value : '',
            element.name === 'quick_action' ? element.value : '',
        ].map(normalize);
        if (actionValues.includes('send_agreement')) return true;

        const href = element.getAttribute?.('href');
        if (href) {
            try {
                const url = new URL(href, window.location.href);
                if (normalize(url.searchParams.get('workflow')) === 'send_agreement') return true;
            } catch (error) {}
        }

        return text.includes('wyslij umowe') || text.includes('wyslij do podpisu');
    };

    const setBusy = (button, busy) => {
        if (!button) return;
        if (busy) {
            if (button.dataset.bcs061Busy === '1') return;
            button.dataset.bcs061Busy = '1';
            button.dataset.bcs061Html = button.tagName === 'INPUT' ? button.value : button.innerHTML;
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            if (button.tagName === 'INPUT') button.value = 'Wysyłanie…';
            else button.textContent = 'Wysyłanie…';
            return;
        }

        button.dataset.bcs061Busy = '0';
        button.disabled = false;
        button.removeAttribute('aria-busy');
        const previous = button.dataset.bcs061Html || '';
        if (button.tagName === 'INPUT') button.value = previous;
        else button.innerHTML = previous;
    };

    const post = async (registration) => {
        const response = await fetch(cfg.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: new URLSearchParams({
                action: 'bcs_046_send_agreement',
                nonce: cfg.nonce,
                registration_id: String(registration),
            }),
        });

        const raw = await response.text();
        let result;
        try {
            result = JSON.parse(raw);
        } catch (error) {
            throw new Error('Serwer zwrócił nieprawidłową odpowiedź podczas wysyłania umowy.');
        }
        if (!response.ok || !result.success) {
            throw new Error(result?.data?.message || cfg.errorFallback);
        }
        return result.data || {};
    };

    const sendAgreement = async (button, registration) => {
        if (!registration || button.dataset.bcs061Busy === '1') return;
        setBusy(button, true);

        try {
            const data = await post(registration);
            const duration = notify(data.message || cfg.successFallback, true, 2000);
            window.clearTimeout(reloadTimer);
            reloadTimer = window.setTimeout(() => window.location.reload(), duration + 80);
        } catch (error) {
            notify(error.message || cfg.errorFallback, false, 2000);
            setBusy(button, false);
        }
    };

    // Listener capture jest rejestrowany przed skryptem 0.46. Dzięki
    // stopImmediatePropagation stary kod z alert() nie otrzymuje kliknięcia.
    document.addEventListener('click', (event) => {
        const button = event.target.closest?.('button,a,input[type="submit"]');
        if (!isSendAgreement(button)) return;

        const id = registrationId(button);
        if (!id) return;

        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();
        sendAgreement(button, id);
    }, true);
})();
