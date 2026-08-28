(() => {
    let active = false;
    document.addEventListener('click', async event => {
        const button = event.target.closest('[data-qualification-organizer-sign]');
        if (!button) return;
        event.preventDefault();
        if (active) return;
        active = true;
        const modal = document.createElement('div');
        modal.className = 'bcs-otp079 bcs-card-organizer-modal';
        modal.innerHTML = `<div class="bcs-otp079-backdrop" data-close></div><div class="bcs-otp079-dialog" role="dialog" aria-modal="true" aria-labelledby="bcs-card-org-title"><button type="button" class="bcs-otp079-close" data-close aria-label="Zamknij">×</button><h2 id="bcs-card-org-title">Podpis karty kwalifikacyjnej</h2><p data-recipient>Wczytywanie…</p><div data-review><button type="button" class="button" data-open disabled>Otwórz kartę kwalifikacyjną</button><iframe title="Podgląd karty kwalifikacyjnej" referrerpolicy="no-referrer" hidden></iframe><label class="bcs-otp079-label"><input type="checkbox" data-read disabled> <span data-declaration></span></label><button type="button" class="button button-primary" data-send disabled>Wyślij kod SMS</button></div><form hidden><label class="bcs-otp079-label">6-cyfrowy kod SMS<input class="bcs-otp079-code" name="code" type="text" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code" autocapitalize="off" spellcheck="false" enterkeyhint="done" required></label><small class="bcs-otp079-note">Kod SMS możesz wpisać lub uzupełnić automatycznie z wiadomości.</small><p data-timer></p><div class="bcs-otp079-actions"><button type="button" class="button" data-close>Anuluj</button><button type="submit" class="button button-primary">Potwierdź podpis</button></div><button type="button" class="button" data-resend hidden>Wyślij nowy kod</button></form><p class="bcs-otp079-error" data-error role="alert"></p></div>`;
        document.body.append(modal);document.body.classList.add('bcs-otp079-open');
        const q = selector => modal.querySelector(selector);
        const frame=q('iframe'), form=q('form'), read=q('[data-read]'), send=q('[data-send]');
        let busy=false, context, expires=0, timer;
        const close = () => { if(busy)return;clearInterval(timer);modal.remove();document.body.classList.remove('bcs-otp079-open');active=false;document.removeEventListener('keydown',keys);button.focus(); };
        const keys = e => {
            if(e.key==='Escape')close();
            if(e.key==='Tab'){
                const items=[...modal.querySelectorAll('button,input,iframe')].filter(n=>!n.disabled && n.getClientRects().length);
                if(e.shiftKey && document.activeElement===items[0]){e.preventDefault();items.at(-1)?.focus();}
                else if(!e.shiftKey && document.activeElement===items.at(-1)){e.preventDefault();items[0]?.focus();}
            }
        };
        document.addEventListener('keydown',keys);modal.querySelectorAll('[data-close]').forEach(n=>n.addEventListener('click',close));q('[data-close]').focus();
        const request = async (op, data) => {
            const url=new URL(button.href);url.searchParams.set('op',op);
            const response=await fetch(url,{credentials:'same-origin',cache:'no-store',referrerPolicy:'no-referrer',...(data?{method:'POST',body:new URLSearchParams({...data,response:'json'})}:{})});
            const result=await response.json();if(!result.success)throw new Error(result.data?.message||'Nie udało się wykonać operacji.');return result.data;
        };
        const tick = () => {
            const left=Math.max(0,Math.ceil(expires-Date.now()/1000));
            q('[data-timer]').textContent=left?`Kod ważny jeszcze ${Math.floor(left/60)}:${String(left%60).padStart(2,'0')}`:'Kod wygasł. Wyślij nowy kod.';
            q('[type="submit"]').disabled=busy||!left;q('[data-resend]').hidden=!!left;q('[data-resend]').disabled=busy;
        };
        const operation = async op => {
            if(busy||read.disabled||!read.checked)return;
            busy=true;send.disabled=true;q('[data-error]').textContent='';tick();
            try {
                const data=await request(op,{card_nonce:context.nonce,read:'1',code:q('[name="code"]').value});
                if(data.signed){q('[data-recipient]').textContent=data.message;window.location.reload();return;}
                expires=Number(data.expires);q('[data-review]').hidden=true;form.hidden=false;q('[data-recipient]').textContent=data.message;q('#bcs-card-org-title').textContent='Kod SMS Organizatora';q('[name="code"]').value='';q('[name="code"]').focus();
            } catch(e){q('[data-error]').textContent=e.message;}
            finally{busy=false;send.disabled=!read.checked;tick();}
        };
        q('[data-open]').addEventListener('click',()=>{frame.hidden=false;frame.src=context.document_url;modal.classList.add('is-reviewing');});
        frame.addEventListener('load',()=>{try{if(frame.contentDocument?.querySelector('meta[name="bcs-card-reviewed"]')?.content===context?.hash)read.disabled=false;}catch(_){} });
        read.addEventListener('change',()=>{send.disabled=!read.checked;});
        send.addEventListener('click',()=>{
            modal.classList.remove('is-reviewing');frame.hidden=true;
            if(expires>Date.now()/1000){q('[data-review]').hidden=true;form.hidden=false;q('#bcs-card-org-title').textContent='Kod SMS Organizatora';q('[name="code"]').focus();tick();}
            else operation('send');
        });
        q('[data-resend]').addEventListener('click',()=>operation('send'));
        q('[name="code"]').addEventListener('input',e=>{e.target.value=e.target.value.replace(/\D/g,'').slice(0,6);});
        form.addEventListener('submit',e=>{e.preventDefault();if(/^[0-9]{6}$/.test(q('[name="code"]').value))operation('sign');});
        try{context=await request('signing_context');if(!modal.isConnected)return;expires=Number(context.expires||0);q('[data-recipient]').textContent=context.name+' · SMS: '+context.phone;q('[data-declaration]').textContent=context.declaration;q('[data-open]').disabled=false;timer=setInterval(tick,1000);}
        catch(e){q('[data-error]').textContent=e.message;}
    });
})();
