(() => {
    'use strict';

    const cfg = window.BCSCardForm060 || null;
    if (!cfg || !cfg.registrationId || !cfg.ajaxUrl) return;

    let lastDisplayHtml = String(cfg.initialHtml || '');
    let busy = false;

    const popup = (message, ok = true) => {
        if (typeof window.bcsPopup0190 === 'function') {
            window.bcsPopup0190(message, ok);
            return;
        }
        window.alert(message);
    };

    const findPanel = () => {
        const panels = Array.from(document.querySelectorAll('.bcs-crm-layout .bcs-accordion-panel, .bcs-accordion-panel'));
        return panels.find((panel) => {
            const title = panel.querySelector('summary strong')?.textContent || '';
            return /Dane z formularza/i.test(title);
        }) || null;
    };

    const getContent = () => findPanel()?.querySelector('.bcs-accordion-content') || null;

    const removeLegacyVerification = () => {
        document.querySelectorAll('.bcs-crm-layout section.bcs-form-verification').forEach((section) => section.remove());
    };

    const mount = (html) => {
        const panel = findPanel();
        const content = panel?.querySelector('.bcs-accordion-content');
        if (!panel || !content) return false;

        const title = panel.querySelector('summary strong');
        if (title) title.textContent = 'Dane z formularza obozowego';

        content.innerHTML = String(html || '<p class="bcs-muted">Brak danych formularza.</p>');
        removeLegacyVerification();
        return true;
    };

    const post = async (data) => {
        const body = new URLSearchParams();
        Object.entries(data).forEach(([key, value]) => body.set(key, String(value ?? '')));

        const response = await fetch(cfg.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body,
        });

        const text = await response.text();
        let json;
        try {
            json = JSON.parse(text);
        } catch (error) {
            throw new Error('Serwer zwrócił nieprawidłową odpowiedź. Odśwież Kartę Zgłoszenia.');
        }
        if (!response.ok || !json.success) {
            throw new Error(json?.data?.message || 'Nie udało się wykonać działania.');
        }
        return json.data || {};
    };

    const refreshDisplay = async () => {
        const data = await post({
            action: cfg.displayAction,
            registration_id: cfg.registrationId,
            nonce: cfg.crmNonce,
        });
        lastDisplayHtml = String(data.html || '');
        mount(lastDisplayHtml);
        return data;
    };

    const fieldElement = (meta, values) => {
        const wrapper = document.createElement('label');
        wrapper.className = 'bcs-card-form-field-060' + (meta.wide ? ' is-wide' : '');

        const label = document.createElement('span');
        label.textContent = meta.label || meta.name;
        wrapper.appendChild(label);

        let input;
        if (meta.type === 'textarea') {
            input = document.createElement('textarea');
            input.rows = 4;
        } else {
            input = document.createElement('input');
            input.type = meta.type === 'checkbox' ? 'checkbox' : (meta.type || 'text');
            if (meta.type === 'number') input.step = '0.01';
        }
        input.name = meta.name;
        if (meta.type === 'checkbox') input.checked = Boolean(Number(values[meta.name] || 0));
        else input.value = values[meta.name] ?? '';
        wrapper.appendChild(input);
        return wrapper;
    };

    const renderEditor = (values) => {
        const root = document.createElement('form');
        root.className = 'bcs-card-form-editor-060';
        root.noValidate = true;

        const intro = document.createElement('div');
        intro.className = 'bcs-card-form-toolbar-060';
        intro.innerHTML = '<div><strong>Edycja Formularza Obozowego</strong><span>Zmień dane i zapisz. Układ grup pozostaje taki sam jak w podglądzie.</span></div>';
        root.appendChild(intro);

        const sections = document.createElement('div');
        sections.className = 'bcs-card-form-sections-060';
        (cfg.editorGroups || []).forEach((group) => {
            const section = document.createElement('section');
            section.className = 'bcs-card-form-section-060';
            const heading = document.createElement('h3');
            heading.textContent = group.title || '';
            const grid = document.createElement('div');
            grid.className = 'bcs-card-form-editor-grid-060';
            (group.fields || []).forEach((meta) => grid.appendChild(fieldElement(meta, values)));
            section.append(heading, grid);
            sections.appendChild(section);
        });
        root.appendChild(sections);

        const actions = document.createElement('div');
        actions.className = 'bcs-card-form-editor-actions-060';
        actions.innerHTML = '<button type="button" class="button bcs-card-form-cancel-060">Anuluj</button><button type="submit" class="button button-primary">Zapisz dane</button>';
        root.appendChild(actions);

        const content = getContent();
        if (content) content.replaceChildren(root);
    };

    const openEditor = async () => {
        if (busy) return;
        busy = true;
        try {
            const data = await post({
                action: cfg.getAction,
                registration_id: cfg.registrationId,
                nonce: cfg.editorNonce,
            });
            if (data.locked) {
                popup(data.message || 'Edycja danych jest już zablokowana.', false);
                return;
            }
            renderEditor(data.values || {});
        } catch (error) {
            popup(error.message || 'Nie udało się otworzyć edycji formularza.', false);
        } finally {
            busy = false;
        }
    };

    const saveEditor = async (form) => {
        if (busy) return;
        busy = true;
        const submit = form.querySelector('button[type="submit"]');
        if (submit) {
            submit.disabled = true;
            submit.textContent = 'Zapisywanie…';
        }

        const data = {
            action: cfg.saveAction,
            registration_id: cfg.registrationId,
            nonce: cfg.editorNonce,
        };
        form.querySelectorAll('[name]').forEach((field) => {
            data[field.name] = field.type === 'checkbox' ? (field.checked ? '1' : '') : field.value;
        });

        try {
            const result = await post(data);
            await refreshDisplay();
            popup(result.message || 'Dane Formularza Obozowego zostały zapisane.', true);
        } catch (error) {
            popup(error.message || 'Nie udało się zapisać danych.', false);
            if (submit) {
                submit.disabled = false;
                submit.textContent = 'Zapisz dane';
            }
        } finally {
            busy = false;
        }
    };

    const verifyForm = async (form) => {
        if (busy) return;
        busy = true;
        const button = form.querySelector('button[type="submit"]');
        if (button) {
            button.disabled = true;
            button.textContent = 'Potwierdzanie…';
        }

        try {
            const data = await post({
                action: cfg.verifyAction,
                registration_id: cfg.registrationId,
                quick_action: 'verify_form',
                nonce: cfg.crmNonce,
            });
            await refreshDisplay();
            popup(data.message || 'Formularz Obozowy został zaakceptowany.', true);
            window.setTimeout(() => window.location.reload(), 700);
        } catch (error) {
            popup(error.message || 'Nie udało się potwierdzić formularza.', false);
            if (button) {
                button.disabled = false;
                button.textContent = 'Potwierdź poprawność formularza obozowego';
            }
        } finally {
            busy = false;
        }
    };

    document.addEventListener('click', (event) => {
        const edit = event.target.closest('.bcs-card-form-edit-060');
        if (edit) {
            event.preventDefault();
            event.stopImmediatePropagation();
            openEditor();
            return;
        }

        const cancel = event.target.closest('.bcs-card-form-cancel-060');
        if (cancel) {
            event.preventDefault();
            event.stopImmediatePropagation();
            mount(lastDisplayHtml);
        }
    }, true);

    document.addEventListener('submit', (event) => {
        const editor = event.target.closest('.bcs-card-form-editor-060');
        if (editor) {
            event.preventDefault();
            event.stopImmediatePropagation();
            saveEditor(editor);
            return;
        }

        const verification = event.target.closest('.bcs-form-verification-form-060');
        if (verification) {
            event.preventDefault();
            event.stopImmediatePropagation();
            verifyForm(verification);
        }
    }, true);

    const ensureMounted = () => {
        const content = getContent();
        if (!content) return;
        if (!content.querySelector('.bcs-card-form-root-060')) mount(lastDisplayHtml);
    };

    const start = () => {
        mount(lastDisplayHtml);
        window.setTimeout(ensureMounted, 80);
        window.setTimeout(ensureMounted, 300);
        window.setTimeout(ensureMounted, 900);
        new MutationObserver(ensureMounted).observe(document.body, {childList: true, subtree: true});
    };

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once: true});
    else start();
})();
