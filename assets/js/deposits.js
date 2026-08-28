(() => {
    let dialog, opener, busy = false;
    const money = cents => new Intl.NumberFormat('pl-PL', {style:'currency', currency:'PLN'}).format(cents / 100);
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-bcs-deposit]');
        if (!button || busy) return;
        event.preventDefault();
        if (!dialog) {
            dialog = document.createElement('dialog');
            dialog.className = 'bcs-deposit-dialog';
            dialog.setAttribute('aria-labelledby','bcs-deposit-title');
            dialog.innerHTML = '<form><header><h2 id="bcs-deposit-title">Wpłacono zadatek</h2><button type="button" class="button" data-close aria-label="Zamknij">×</button></header><p data-balance></p><label>Kwota wpłaconego zadatku (zł)<input name="amount" type="text" inputmode="decimal" autocomplete="off" placeholder="np. 500,00" required></label><p>Zadatek pomniejszy kwotę pozostałą do zapłaty. Pozostałą część zaksięgujesz przyciskiem „Zaksięguj wpłatę”.</p><p role="status" aria-live="polite" data-message></p><button type="submit" class="button button-primary">Zaksięguj zadatek</button></form>';
            document.body.append(dialog);
            dialog.querySelector('[data-close]').addEventListener('click', () => { if (!busy) dialog.close(); });
            dialog.addEventListener('cancel', e => { if (busy) e.preventDefault(); });
            dialog.addEventListener('close', () => opener?.focus());
            dialog.querySelector('form').addEventListener('submit', async e => {
                e.preventDefault();
                if (busy) return;
                const value = dialog.querySelector('input').value.trim().replace(',','.');
                const message = dialog.querySelector('[data-message]');
                if (!/^\d{1,8}(?:\.\d{1,2})?$/.test(value)) { message.textContent = 'Wpisz kwotę z maksymalnie dwoma miejscami po przecinku.'; return; }
                const cents = Math.round(Number(value) * 100);
                if (cents <= 0 || cents >= Number(opener.dataset.due)) { message.textContent = 'Zadatek musi być większy od zera i mniejszy od pozostałej kwoty. Całość zaksięguj przyciskiem „Zaksięguj wpłatę”.'; return; }
                busy = true;
                dialog.querySelectorAll('button').forEach(b => { b.disabled = true; });
                message.textContent = 'Zapisywanie wpłaty…';
                try {
                    const response = await fetch(opener.dataset.endpoint, {method:'POST',credentials:'same-origin',body:new URLSearchParams({action:'bcs_record_deposit',registration_id:opener.dataset.registrationId,nonce:opener.dataset.nonce,request_id:opener.dataset.requestId,amount:value})});
                    const result = await response.json();
                    if (!result.success) throw new Error(result.data?.message || 'Nie udało się zapisać zadatku.');
                    message.textContent = result.data.message;
                    window.location.reload();
                } catch (error) {
                    message.textContent = error.message || 'Błąd połączenia. Spróbuj ponownie.';
                    busy = false;
                    dialog.querySelectorAll('button').forEach(b => { b.disabled = false; });
                }
            });
        }
        opener = button;
        dialog.querySelector('input').value = '';
        dialog.querySelector('[data-balance]').textContent = 'Pozostało do zapłaty: ' + money(Number(button.dataset.due));
        dialog.querySelector('[data-message]').textContent = '';
        dialog.showModal();
        dialog.querySelector('input').focus();
    });
})();
