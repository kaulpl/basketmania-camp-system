(() => {
    'use strict';

    if (window.BCSShirtSuggestionController092) {
        window.BCSShirtSuggestionController092.mount();
        return;
    }

    const config = window.BCSShirtSuggestion092 || {};
    const sizes = Array.isArray(config.sizes) ? config.sizes.map(String) : [];
    const hintPrefix = String(config.hintPrefix || 'Sugerowany rozmiar dla podanego wzrostu:');
    if (!sizes.length) return;

    const range = (size) => {
        const numbers = String(size).match(/\d+/g)?.map(Number) || [];
        if (numbers.length < 2) return null;
        return {min: numbers[numbers.length - 2], max: numbers[numbers.length - 1]};
    };

    const suggest = (height) => {
        const value = Number(height);
        if (!Number.isFinite(value) || value <= 0) return '';
        const first = sizes[0];
        const last = sizes[sizes.length - 1];
        for (const size of sizes) {
            const bounds = range(size);
            if (!bounds) continue;
            if (value < bounds.min) return first;
            if (value >= bounds.min && value < bounds.max) return size;
        }
        return last;
    };

    const findPair = (heightInput) => {
        const form = heightInput.closest('form') || document;
        const sizeField = form.querySelector('select[name="shirt_size"],input[name="shirt_size"]');
        return sizeField instanceof HTMLSelectElement ? sizeField : null;
    };

    const isSuggestion = (element) => element instanceof HTMLElement && (
        element.id === 'bcs-shirt-size-suggestion'
        || element.hasAttribute('data-bcs-shirt-hint-092')
        || element.hasAttribute('data-bcs-shirt-hint092')
        || String(element.textContent || '').trim().startsWith(hintPrefix)
    );

    const removeDuplicateHints = (keep = null) => {
        document.querySelectorAll('small,output,[data-bcs-shirt-hint-092],[data-bcs-shirt-hint092]').forEach((element) => {
            if (element !== keep && isSuggestion(element)) element.remove();
        });
    };

    const ensureHint = (select) => {
        const form = select.closest('form') || document;
        let hint = document.getElementById('bcs-shirt-size-suggestion');
        removeDuplicateHints(hint);
        if (hint) {
            if (hint.previousElementSibling !== select) select.insertAdjacentElement('afterend', hint);
            return hint;
        }
        hint = document.createElement('output');
        hint.id = 'bcs-shirt-size-suggestion';
        hint.setAttribute('data-bcs-shirt-hint-092', '1');
        hint.setAttribute('aria-live', 'polite');
        hint.style.display = 'block';
        hint.style.marginTop = '5px';
        hint.style.color = '#64748b';
        select.insertAdjacentElement('afterend', hint);
        return hint;
    };

    const apply = (heightInput, forceIfAutomatic = false) => {
        const form = heightInput.closest('form') || document;
        const visibleHeightInputs = [...form.querySelectorAll('input[name="child_height"]')]
            .filter((input) => input.isConnected && input.getClientRects().length > 0);
        if (visibleHeightInputs.length && visibleHeightInputs[0] !== heightInput) return;
        const select = findPair(heightInput);
        if (!select) return;
        const suggested = suggest(heightInput.value);
        const hint = ensureHint(select);
        if (!suggested) {
            hint.textContent = '';
            return;
        }

        hint.textContent = `${hintPrefix} ${suggested}`;
        const previousAutomatic = String(select.dataset.bcsSuggested092 || '');
        const current = String(select.value || '').trim();
        const maySelect = current === '' || current === previousAutomatic || forceIfAutomatic;
        if (maySelect && sizes.includes(suggested)) {
            select.value = suggested;
            select.dataset.bcsSuggested092 = suggested;
            select.dispatchEvent(new Event('change', {bubbles: true}));
        }
    };

    const mountHeight = (heightInput) => {
        if (!(heightInput instanceof HTMLInputElement) || heightInput.dataset.bcsShirtHeight092 === '1') return;
        heightInput.dataset.bcsShirtHeight092 = '1';
        const update = () => apply(heightInput, false);
        heightInput.addEventListener('input', update);
        heightInput.addEventListener('change', update);
        window.setTimeout(() => apply(heightInput, false), 0);
        window.setTimeout(() => apply(heightInput, false), 120);
    };

    const mount = (root = document) => {
        removeDuplicateHints(document.getElementById('bcs-shirt-size-suggestion'));
        if (root instanceof HTMLInputElement && root.matches('input[name="child_height"]')) mountHeight(root);
        const scope = root instanceof Element || root instanceof Document ? root : document;
        scope.querySelectorAll('input[name="child_height"]').forEach(mountHeight);
    };

    window.BCSShirtSuggestionController092 = {mount};

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => mount(), {once: true});
    } else {
        mount();
    }

    new MutationObserver((records) => {
        records.forEach((record) => record.addedNodes.forEach((node) => {
            if (node.nodeType !== Node.ELEMENT_NODE) return;
            if (isSuggestion(node) && node.id !== 'bcs-shirt-size-suggestion') {
                node.remove();
                return;
            }
            mount(node);
        }));
    }).observe(document.documentElement, {childList: true, subtree: true});
})();
