(() => {
    'use strict';

    const cfg = window.BCSInvoiceProfile086 || null;
    if (!cfg || !cfg.registrationId || !cfg.ajaxUrl) return;

    const normalize = (value) => String(value || '').trim().toLowerCase();
    const notify = (message, ok = true) => {
        if (typeof window.bcsNotify === 'function') return window.bcsNotify(message, ok);
        if (typeof window.bcsPopup0190 === 'function') return window.bcsPopup0190(message, ok);
        window.alert(message);
    };

    const findFormPanel = () => Array.from(document.querySelectorAll('.bcs-accordion-panel')).find((panel) => {
        const title = panel.querySelector('summary strong')?.textContent || '';
        return /Dane z formularza/i.test(title);
    }) || null;

    const filterLegacyEditorGroups = () => {
        const legacy = window.BCSCardForm060;
        if (!legacy || !Array.isArray(legacy.editorGroups)) return;
        legacy.editorGroups = legacy.editorGroups.filter((group) => normalize(group?.title) !== 'dane do faktury');
    };

    const removeLegacyInvoiceSection = () => {
        const panel = findFormPanel();
        const content = panel?.querySelector('.bcs-accordion-content');
        if (!content) return;
        content.querySelectorAll('.bcs-card-form-section-060').forEach((section) => {
            if (normalize(section.querySelector('h3')?.textContent) === 'dane do faktury') section.remove();
        });
    };

    const toggleNip = (form) => {
        if (!form) return;
        const company = form.elements?.billing_type?.value === 'company';
        const row = form.querySelector('[data-bcs-nip-row-086]');
        const input = form.elements?.billing_nip;
        if (row) row.hidden = !company;
        if (input) {
            input.required = company;
            if (!company) input.value = '';
        }
    };

    const setFormData = (form, data) => {
        if (!form || !data) return;
        const map = {
            billing_type: 'type',
            billing_name: 'name',
            billing_street: 'street',
            billing_postal_code: 'postal',
            billing_city: 'city',
            billing_nip: 'nip',
            billing_notes: 'notes',
            billing_ksef_description: 'description',
        };
        Object.entries(map).forEach(([field, key]) => {
            const el = form.elements?.[field];
            if (el) el.value = data[key] ?? '';
        });
        toggleNip(form);
    };

    const setHash = (name) => {
        if (!window.history?.replaceState) return;
        if (name === 'invoice') {
            history.replaceState(null, '', `${location.pathname}${location.search}#dane-do-faktury`);
        } else if (location.hash === '#dane-do-faktury') {
            history.replaceState(null, '', `${location.pathname}${location.search}`);
        }
    };

    const mount = () => {
        filterLegacyEditorGroups();
        removeLegacyInvoiceSection();

        if (document.querySelector('.bcs-card-data-tabs-086')) return true;
        const formPanel = findFormPanel();
        const template = document.getElementById('bcs-invoice-profile-template-086');
        if (!formPanel || !template?.content?.firstElementChild) return false;

        const profile = template.content.firstElementChild.cloneNode(true);
        profile.hidden = true;

        const tabs = document.createElement('div');
        tabs.className = 'bcs-card-data-tabs-086';
        tabs.setAttribute('role', 'tablist');
        tabs.innerHTML = '<button type="button" class="is-active" role="tab" aria-selected="true" data-bcs-data-tab-086="form">Formularz obozowy</button><button type="button" role="tab" aria-selected="false" data-bcs-data-tab-086="invoice">Dane do Faktury</button>';

        formPanel.insertAdjacentElement('beforebegin', tabs);
        formPanel.insertAdjacentElement('afterend', profile);

        const show = (name) => {
            const invoice = name === 'invoice';
            formPanel.hidden = invoice;
            profile.hidden = !invoice;
            tabs.querySelectorAll('[data-bcs-data-tab-086]').forEach((button) => {
                const active = button.dataset.bcsDataTab086 === name;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            setHash(name);
        };

        tabs.addEventListener('click', (event) => {
            const button = event.target.closest('[data-bcs-data-tab-086]');
            if (!button) return;
            event.preventDefault();
            show(button.dataset.bcsDataTab086 || 'form');
        });

        if (location.hash === '#dane-do-faktury') show('invoice');
        toggleNip(profile.querySelector('[data-bcs-invoice-form-086]'));
        return true;
    };

    const remountCleanup = () => {
        filterLegacyEditorGroups();
        removeLegacyInvoiceSection();
        window.setTimeout(removeLegacyInvoiceSection, 120);
        window.setTimeout(removeLegacyInvoiceSection, 500);
        window.setTimeout(removeLegacyInvoiceSection, 1200);
    };

    document.addEventListener('click', (event) => {
        const edit = event.target.closest('[data-bcs-invoice-edit-086]');
        if (edit) {
            const panel = edit.closest('[data-bcs-invoice-profile-086]');
            const form = panel?.querySelector('[data-bcs-invoice-form-086]');
            const view = panel?.querySelector('[data-bcs-invoice-view-086]');
            const actions = panel?.querySelector('[data-bcs-invoice-actions-086]');
            if (form) form.hidden = false;
            if (view) view.hidden = true;
            if (actions) actions.hidden = true;
            toggleNip(form);
            return;
        }

        const cancel = event.target.closest('[data-bcs-invoice-cancel-086]');
        if (cancel) {
            const panel = cancel.closest('[data-bcs-invoice-profile-086]');
            const form = panel?.querySelector('[data-bcs-invoice-form-086]');
            const view = panel?.querySelector('[data-bcs-invoice-view-086]');
            const actions = panel?.querySelector('[data-bcs-invoice-actions-086]');
            if (form) form.hidden = true;
            if (view) view.hidden = false;
            if (actions) actions.hidden = false;
            return;
        }

        const parent = event.target.closest('[data-bcs-fill-parent-086]');
        if (parent) {
            setFormData(parent.closest('form'), cfg.parent || {});
            return;
        }

        const company = event.target.closest('[data-bcs-fill-company-086]');
        if (company) {
            setFormData(company.closest('form'), cfg.company || {});
            return;
        }

        if (event.target.closest('.bcs-card-form-edit-060, .bcs-card-form-cancel-060')) remountCleanup();
    }, true);

    document.addEventListener('change', (event) => {
        if (event.target.matches('[data-bcs-invoice-form-086] [name="billing_type"]')) {
            toggleNip(event.target.closest('form'));
        }
    }, true);

    document.addEventListener('submit', async (event) => {
        const form = event.target.closest('[data-bcs-invoice-form-086]');
        if (form) {
            event.preventDefault();
            event.stopImmediatePropagation();
            const submit = form.querySelector('button[type="submit"]');
            const original = submit?.textContent || 'Zapisz dane do faktury';
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
                if (!response.ok || !json.success) throw new Error(json?.data?.message || 'Nie udało się zapisać danych do faktury.');
                notify(json.data?.message || 'Dane do faktury zostały zapisane.', true);
                history.replaceState(null, '', `${location.pathname}${location.search}#dane-do-faktury`);
                window.setTimeout(() => window.location.reload(), 400);
            } catch (error) {
                notify(error.message || 'Nie udało się zapisać danych do faktury.', false);
                if (submit) {
                    submit.disabled = false;
                    submit.textContent = original;
                }
            }
            return;
        }

        if (event.target.closest('.bcs-card-form-editor-060')) remountCleanup();
    }, true);

    const start = () => {
        filterLegacyEditorGroups();
        mount();
        [80, 250, 700, 1400].forEach((delay) => window.setTimeout(() => {
            mount();
            removeLegacyInvoiceSection();
        }, delay));
    };

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once: true});
    else start();
})();
