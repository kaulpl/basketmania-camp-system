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

// Card invitations open a signer-scoped view of the Parent Panel.
(() => {
    document.querySelectorAll('.bcs-qualification-signing').forEach(root => {
        const form = root.querySelector('[data-card-sign-form]');
        const open = root.querySelector('[data-card-open]');
        const documentModal = root.querySelector('[data-card-document]');
        const frame = documentModal.querySelector('iframe');
        const otpModal = root.querySelector('[data-card-otp]');
        const read = form?.querySelector('[name="read"]');
        const send = root.querySelector('[data-card-send]');
        const sign = root.querySelector('[data-card-sign]');
        const message = root.querySelector('[data-card-message]');
        const otpMessage = root.querySelector('[data-card-otp-message]');
        let busy = false;
        let expires = Number(root.dataset.cardExpires || 0);
        let lastFocus;
        const show = modal => {
            lastFocus = document.activeElement;
            modal.hidden = false;
            modal.querySelector('input, button')?.focus();
        };
        const close = modal => { modal.hidden = true; lastFocus?.focus(); };
        root.querySelectorAll('[data-card-close]').forEach(button => button.addEventListener('click', () => close(button.closest('.bcs-modal'))));
        root.addEventListener('keydown', event => {
            const modal = event.target.closest('.bcs-modal');
            if (!modal || modal.hidden) return;
            if (event.key === 'Escape') close(modal);
            if (event.key === 'Tab') {
                const items = [...modal.querySelectorAll('button, input, iframe')].filter(item => !item.disabled);
                const first = items[0], last = items[items.length - 1];
                if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
                else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
            }
        });
        open.addEventListener('click', () => {
            frame.src = open.dataset.documentUrl;
            show(documentModal);
        });
        frame.addEventListener('load', () => {
            // Do not enable consent when WordPress returned an error/expired-link page.
            try {
                const reviewed = frame.contentDocument?.querySelector('meta[name="bcs-card-reviewed"]');
                if (read && reviewed?.content === root.dataset.cardHash) read.disabled = false;
            } catch (_) { if (message) message.textContent = 'Nie udało się otworzyć dokumentu. Odśwież panel i spróbuj ponownie.'; }
        });
        if (!form) return;
        const update = () => {
            send.disabled = busy || read.disabled || !read.checked;
            sign.disabled = busy || read.disabled || !read.checked || expires <= Date.now() / 1000;
            const left = Math.max(0, Math.ceil(expires - Date.now() / 1000));
            root.querySelector('[data-card-timer]').textContent = left ? `Kod ważny jeszcze ${Math.floor(left / 60)}:${String(left % 60).padStart(2, '0')}` : 'Kod wygasł. Zamknij okno i wyślij nowy SMS.';
        };
        read.addEventListener('change', update);
        setInterval(update, 1000);
        const request = async op => {
            if (busy || read.disabled || !read.checked) return;
            busy = true; update(); message.textContent = ''; otpMessage.textContent = '';
            try {
                const body = new FormData(form);
                body.set('op', op);
                const endpoint = new URL(root.dataset.cardEndpoint); endpoint.searchParams.set('op', op);
                const response = await fetch(endpoint.toString(), {method:'POST', body, credentials:'same-origin', cache:'no-store', referrerPolicy:'no-referrer'});
                const result = await response.json();
                if (!result.success) throw new Error(result.data?.message || 'Nie udało się potwierdzić operacji.');
                message.textContent = result.data.message;
                if (result.data.signed) { window.location.reload(); return; }
                expires = Number(result.data.expires || 0);
                show(otpModal); form.querySelector('[name="code"]').focus();
            } catch (error) {
                (op === 'sign' ? otpMessage : message).textContent = error.message || 'Błąd połączenia. Spróbuj ponownie.';
            } finally { busy = false; update(); }
        };
        send.addEventListener('click', () => {
            if (expires > Date.now() / 1000) { show(otpModal); form.querySelector('[name="code"]').focus(); }
            else request('send');
        });
        form.addEventListener('submit', event => {
            event.preventDefault();
            if (!/^[0-9]{6}$/.test(form.querySelector('[name="code"]').value)) { otpMessage.textContent = 'Wpisz sześciocyfrowy kod SMS.'; return; }
            request('sign');
        });
        update();
    });
})();

// Organizer preview stays in the registration; authenticated signing runs in the frame.
(() => {
    let dialog, frame, opener, initialStage, refresh = false;
    const close = () => { if (dialog?.open) dialog.close(); };
    document.addEventListener('click', event => {
        const link = event.target.closest('[data-qualification-admin-preview]');
        if (!link || link.hasAttribute('data-qualification-organizer-sign') || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        if (!dialog) {
            dialog = document.createElement('dialog');
            dialog.className = 'bcs-qualification-admin-dialog';
            dialog.setAttribute('aria-labelledby', 'bcs-qualification-admin-title');
            dialog.innerHTML = '<header><h2 id="bcs-qualification-admin-title">Karta kwalifikacyjna</h2><button type="button" class="button" aria-label="Zamknij podgląd karty">Zamknij ×</button></header><iframe title="Podgląd i podpis karty kwalifikacyjnej" referrerpolicy="no-referrer"></iframe>';
            document.body.append(dialog);
            frame = dialog.querySelector('iframe');
            dialog.querySelector('button').addEventListener('click', close);
            dialog.addEventListener('click', e => {
                const rect = dialog.getBoundingClientRect();
                if (e.target === dialog && (e.clientX < rect.left || e.clientX > rect.right || e.clientY < rect.top || e.clientY > rect.bottom)) close();
            });
            frame.addEventListener('load', () => {
                try {
                    const stage = frame.contentDocument?.querySelector('meta[name="bcs-card-stage"]')?.content;
                    if (stage) {
                        if (initialStage && stage !== initialStage) refresh = true;
                        initialStage = stage;
                    }
                    frame.contentDocument?.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
                } catch (_) { /* The server still handles authorization and error messages. */ }
            });
            dialog.addEventListener('close', () => {
                frame.src = 'about:blank';
                opener?.focus();
                if (refresh) window.location.reload();
            });
        }
        opener = link; initialStage = null; refresh = false;
        frame.src = link.href;
        dialog.showModal();
        dialog.querySelector('button').focus();
    });
})();
