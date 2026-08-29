(() => {
    'use strict';
    if (window.BCSShirtSuggestionController092) { window.BCSShirtSuggestionController092.mount(); return; }

    const config = window.BCSShirtSuggestion092 || {};
    const sizes = Array.isArray(config.sizes) ? config.sizes.map(String) : [];
    const warningPrefix = 'Sugerowany rozmiar stroju dla podanego wzrostu:';
    const legacyPrefix = String(config.hintPrefix || 'Sugerowany rozmiar dla podanego wzrostu:');
    if (!sizes.length) return;

    const range = size => {
        const numbers = String(size).match(/\d+/g)?.map(Number) || [];
        return numbers.length < 2 ? null : {min:numbers[numbers.length - 2],max:numbers[numbers.length - 1]};
    };
    const suggest = height => {
        const value = Number(height);
        if (!Number.isFinite(value) || value <= 0) return '';
        for (const size of sizes) {
            const bounds = range(size);
            if (!bounds) continue;
            if (value < bounds.min) return sizes[0];
            if (value >= bounds.min && value < bounds.max) return size;
        }
        return sizes[sizes.length - 1];
    };
    const findPair = heightInput => {
        const form = heightInput.closest('form') || document;
        const field = form.querySelector('select[name="shirt_size"],input[name="shirt_size"]');
        return field instanceof HTMLSelectElement ? field : null;
    };
    const isOldHint = element => element instanceof HTMLElement && (
        element.id === 'bcs-shirt-size-suggestion'
        || element.hasAttribute('data-bcs-shirt-hint-092')
        || element.hasAttribute('data-bcs-shirt-hint092')
        || String(element.textContent || '').trim().startsWith(legacyPrefix)
    );
    const removeOldHints = () => document.querySelectorAll('small,output,[data-bcs-shirt-hint-092],[data-bcs-shirt-hint092]').forEach(element => {
        if (isOldHint(element)) element.remove();
    });
    const ensureWarning = select => {
        const form = select.closest('form') || document;
        let warning = form.querySelector('[data-bcs-shirt-warning-092]');
        form.querySelectorAll('[data-bcs-shirt-warning-092]').forEach(element => { if (element !== warning) element.remove(); });
        if (!warning) {
            warning = document.createElement('small');
            warning.setAttribute('data-bcs-shirt-warning-092','1');
            warning.setAttribute('role','status');
            warning.setAttribute('aria-live','polite');
            Object.assign(warning.style,{display:'none',marginTop:'6px',padding:'7px 9px',border:'1px solid #facc15',borderRadius:'7px',background:'#fef9c3',color:'#713f12',fontWeight:'700'});
        }
        if (warning.previousElementSibling !== select) select.insertAdjacentElement('afterend',warning);
        return warning;
    };
    const updateWarning = (select,suggested) => {
        const warning = ensureWarning(select);
        const current = String(select.value || '').trim();
        const differs = suggested !== '' && current !== '' && current !== suggested;
        warning.textContent = differs ? `⚠ ${warningPrefix} ${suggested}` : '';
        warning.style.display = differs ? 'block' : 'none';
    };
    const bindSelect = (select,heightInput) => {
        if (select.dataset.bcsShirtWarning092 === '1') return;
        select.dataset.bcsShirtWarning092 = '1';
        select.addEventListener('change',() => updateWarning(select,suggest(heightInput.value)));
    };
    const apply = (heightInput,heightChanged=false) => {
        const form = heightInput.closest('form') || document;
        const visible = [...form.querySelectorAll('input[name="child_height"]')].filter(input => input.isConnected && input.getClientRects().length > 0);
        if (visible.length && visible[0] !== heightInput) return;
        const select = findPair(heightInput);
        if (!select) return;
        bindSelect(select,heightInput);
        const suggested = suggest(heightInput.value);
        if (!suggested) { updateWarning(select,''); return; }
        const previous = String(select.dataset.bcsSuggested092 || '');
        const current = String(select.value || '').trim();
        if ((heightChanged || current === '' || current === previous) && sizes.includes(suggested)) {
            select.value = suggested;
            select.dataset.bcsSuggested092 = suggested;
            select.dispatchEvent(new Event('change',{bubbles:true}));
        } else updateWarning(select,suggested);
    };
    const mountHeight = heightInput => {
        if (!(heightInput instanceof HTMLInputElement) || heightInput.dataset.bcsShirtHeight092 === '1') return;
        heightInput.dataset.bcsShirtHeight092 = '1';
        const update = () => apply(heightInput,true);
        heightInput.addEventListener('input',update);
        heightInput.addEventListener('change',update);
        window.setTimeout(() => apply(heightInput,false),0);
        window.setTimeout(() => apply(heightInput,false),120);
    };
    const mount = (root=document) => {
        removeOldHints();
        if (root instanceof HTMLInputElement && root.matches('input[name="child_height"]')) mountHeight(root);
        const scope = root instanceof Element || root instanceof Document ? root : document;
        scope.querySelectorAll('input[name="child_height"]').forEach(mountHeight);
    };
    window.BCSShirtSuggestionController092 = {mount};
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded',() => mount(),{once:true}); else mount();
    new MutationObserver(records => records.forEach(record => record.addedNodes.forEach(node => {
        if (node.nodeType !== Node.ELEMENT_NODE) return;
        if (isOldHint(node)) { node.remove(); return; }
        mount(node);
    }))).observe(document.documentElement,{childList:true,subtree:true});
})();
