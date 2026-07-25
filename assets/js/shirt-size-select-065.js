(() => {
    'use strict';

    const config = window.BCSShirtSizes065 || {};
    const sizes = Array.isArray(config.sizes) ? config.sizes.map(String) : [];
    const placeholder = String(config.placeholder || 'Wybierz rozmiar');
    if (!sizes.length) return;

    const buildOptions = (select, currentValue) => {
        const current = String(currentValue || '').trim();
        select.replaceChildren();

        const empty = document.createElement('option');
        empty.value = '';
        empty.textContent = placeholder;
        select.appendChild(empty);

        const values = [...sizes];
        if (current && !values.includes(current)) values.push(current);
        values.forEach((size) => {
            const option = document.createElement('option');
            option.value = size;
            option.textContent = size;
            option.selected = size === current;
            select.appendChild(option);
        });
        select.value = current;
    };

    const upgradeField = (field) => {
        if (!(field instanceof HTMLElement) || field.dataset.bcsShirtSelect065 === '1') return;
        const current = String(field.value || field.getAttribute('value') || '').trim();

        if (field instanceof HTMLSelectElement) {
            buildOptions(field, current);
            field.dataset.bcsShirtSelect065 = '1';
            return;
        }
        if (!(field instanceof HTMLInputElement)) return;

        const select = document.createElement('select');
        Array.from(field.attributes).forEach((attribute) => {
            if (attribute.name === 'type' || attribute.name === 'value') return;
            select.setAttribute(attribute.name, attribute.value);
        });
        select.name = field.name || 'shirt_size';
        if (field.id) select.id = field.id;
        select.required = field.required;
        select.disabled = field.disabled;
        select.dataset.bcsShirtSelect065 = '1';
        buildOptions(select, current);
        field.replaceWith(select);
    };

    const upgrade = (root = document) => {
        if (root instanceof Element && root.matches('input[name="shirt_size"],select[name="shirt_size"]')) {
            upgradeField(root);
        }
        const scope = root instanceof Element || root instanceof Document ? root : document;
        scope.querySelectorAll('input[name="shirt_size"],select[name="shirt_size"]').forEach(upgradeField);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => upgrade(), {once: true});
    } else {
        upgrade();
    }

    new MutationObserver((records) => {
        records.forEach((record) => {
            record.addedNodes.forEach((node) => {
                if (node.nodeType === Node.ELEMENT_NODE) upgrade(node);
            });
        });
    }).observe(document.documentElement, {childList: true, subtree: true});
})();
