(() => {
    const update = (toggle) => {
        const root = toggle.closest('form, .bcs-card-form-editor-060, .bcs-card-form-root-060') || toggle.parentElement.parentElement;
        root.querySelectorAll('[name^="second_parent_"]').forEach(input => {
            input.disabled = toggle.checked;
            input.required = !toggle.checked;
            input.closest('label')?.classList.toggle('bcs-parent-disabled', toggle.checked);
        });
    };
    const scan = () => document.querySelectorAll('[name="sole_guardian"]').forEach(toggle => {
        if (!toggle.dataset.qualificationReady) {
            toggle.dataset.qualificationReady = '1';
            toggle.setAttribute('role','switch');
            toggle.closest('label')?.classList.add('bcs-sole-switch');
            toggle.addEventListener('change',()=>update(toggle));
            update(toggle);
        }
    });
    scan(); new MutationObserver(scan).observe(document.body,{childList:true,subtree:true});
})();
