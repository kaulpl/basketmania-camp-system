(() => {
    'use strict';

    const cfg = window.BCSInvoiceProfile110 || null;
    if (!cfg || !cfg.registrationId || !cfg.ajaxUrl) return;

    const notify = (message, ok = true) => {
        if (typeof window.bcsNotify === 'function') return window.bcsNotify(message, ok);
        if (typeof window.bcsPopup0190 === 'function') return window.bcsPopup0190(message, ok);
        window.alert(message);
    };

    const selectedType = (form) => form?.querySelector('input[name="billing_type"]:checked')?.value || 'individual';

    const refreshType = (form) => {
        if (!form) return;
        const company = selectedType(form) === 'company';
        form.querySelectorAll('.bcs-invoice-kind-option-110').forEach((option) => {
            const radio = option.querySelector('input[name="billing_type"]');
            option.classList.toggle('is-selected', !!radio?.checked);
        });
        const nipRow = form.querySelector('[data-bcs-nip-row-110]');
        const nip = form.elements?.billing_nip;
        if (nipRow) nipRow.hidden = !company;
        if (nip) {
            nip.required = company;
            if (!company) nip.value = '';
        }
        const nameLabel = form.querySelector('[data-bcs-name-label-110]');
        if (nameLabel) nameLabel.innerHTML = company ? 'Nazwa firmy <em>*</em>' : 'Imię i nazwisko nabywcy <em>*</em>';
        const required = form.querySelector('[data-bcs-required-copy-110]');
        if (required) required.textContent = company
            ? 'nazwa firmy, NIP, ulica i numer, kod pocztowy, miejscowość'
            : 'imię i nazwisko, ulica i numer, kod pocztowy, miejscowość';
    };

    const fill = (form, data) => {
        if (!form || !data) return;
        const type = data.type === 'company' ? 'company' : 'individual';
        const radio = form.querySelector(`input[name="billing_type"][value="${type}"]`);
        if (radio) radio.checked = true;
        const map = {
            billing_name: 'name',
            billing_street: 'street',
            billing_postal_code: 'postal',
            billing_city: 'city',
            billing_nip: 'nip',
            billing_notes: 'notes',
            billing_ksef_description: 'description',
        };
        Object.entries(map).forEach(([field, key]) => {
            const input = form.elements?.[field];
            if (input) input.value = data[key] ?? '';
        });
        refreshType(form);
    };

    const hideOldInvoiceGenerateAction = () => {
        document.querySelectorAll('.bcs-quick-actions form.bcs-crm-action').forEach((form) => {
            const button = form.querySelector('button[name="bcs_crm_action"][value="invoice_generate"]');
            if (button) form.classList.add('bcs-invoice-action-hidden-110');
        });
    };

    const mount = () => {
        if (document.querySelector('[data-bcs-invoice-profile-110]')) {
            hideOldInvoiceGenerateAction();
            return true;
        }
        const template = document.getElementById('bcs-invoice-profile-template-110');
        const quickActions = document.querySelector('.bcs-quick-actions');
        if (!template?.content?.firstElementChild || !quickActions) return false;
        const panel = template.content.firstElementChild.cloneNode(true);
        quickActions.insertAdjacentElement('beforebegin', panel);
        refreshType(panel.querySelector('[data-bcs-invoice-form-110]'));
        hideOldInvoiceGenerateAction();
        return true;
    };

    document.addEventListener('change', (event) => {
        if (event.target.matches('[data-bcs-invoice-form-110] input[name="billing_type"]')) {
            refreshType(event.target.closest('form'));
        }
    }, true);

    document.addEventListener('click', (event) => {
        const parent = event.target.closest('[data-bcs-fill-parent-110]');
        if (parent) {
            fill(parent.closest('form'), cfg.parent || {});
            return;
        }
        const company = event.target.closest('[data-bcs-fill-company-110]');
        if (company) {
            fill(company.closest('form'), cfg.company || {});
        }
    }, true);

    document.addEventListener('submit', async (event) => {
        const form = event.target.closest('[data-bcs-invoice-form-110]');
        if (!form) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        refreshType(form);
        if (!form.reportValidity()) return;

        const submit = form.querySelector('button[type="submit"]');
        const original = submit?.textContent || 'Zapisz dane nabywcy';
        if (submit) {
            submit.disabled = true;
            submit.textContent = 'Zapisywanie…';
        }

        const body = new URLSearchParams({
            action: cfg.saveAction,
            registration_id: String(cfg.registrationId),
            nonce: cfg.nonce,
        });
        new FormData(form).forEach((value, key) => body.set(key, String(value)));

        try {
            const response = await fetch(cfg.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                body: body.toString(),
            });
            const json = await response.json();
            if (!response.ok || !json.success) throw new Error(json?.data?.message || 'Nie udało się zapisać danych nabywcy.');
            notify(json.data?.message || 'Dane nabywcy zostały zapisane.', true);
            window.setTimeout(() => window.location.reload(), 350);
        } catch (error) {
            notify(error.message || 'Nie udało się zapisać danych nabywcy.', false);
            if (submit) {
                submit.disabled = false;
                submit.textContent = original;
            }
        }
    }, true);

    const start = () => {
        mount();
        hideOldInvoiceGenerateAction();
        [100, 300, 800, 1500].forEach((delay) => window.setTimeout(() => {
            mount();
            hideOldInvoiceGenerateAction();
        }, delay));
    };

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once: true});
    else start();
})();
