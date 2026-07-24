<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_032 {
    public static function init(): void {
        remove_action('admin_footer', ['BCS_Release_030', 'admin_footer']);
        add_action('admin_footer', [__CLASS__, 'admin_footer']);
    }

    public static function admin_footer(): void {
        if (!current_user_can('manage_options')) return;
        $nonce = wp_create_nonce('bcs_admin');
        ?>
        <style>
        .bcs-otp032-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.62);display:flex;align-items:center;justify-content:center;z-index:100000;padding:20px}.bcs-otp032-modal{width:min(520px,100%);background:#fff;border-radius:18px;box-shadow:0 24px 70px rgba(15,23,42,.3);overflow:hidden}.bcs-otp032-head{padding:24px 26px;background:linear-gradient(135deg,#111827,#334155);color:#fff;display:flex;gap:16px;align-items:center}.bcs-otp032-modal.is-list .bcs-otp032-head{background:linear-gradient(135deg,#ea580c,#f97316)}.bcs-otp032-icon{width:48px;height:48px;border-radius:14px;background:rgba(255,255,255,.16);display:grid;place-items:center;font-size:25px}.bcs-otp032-head h2{margin:0;color:#fff;font-size:21px}.bcs-otp032-head p{margin:4px 0 0;color:#fff7ed}.bcs-otp032-body{padding:26px}.bcs-otp032-status{padding:13px 15px;border-radius:10px;background:#f1f5f9;margin-bottom:18px}.bcs-otp032-code{display:flex;gap:8px;justify-content:center;margin:22px 0}.bcs-otp032-code input{width:48px;height:58px;text-align:center;font-size:26px;font-weight:700;border:2px solid #cbd5e1;border-radius:10px}.bcs-otp032-code input:focus{border-color:#ea580c;box-shadow:0 0 0 3px rgba(234,88,12,.15);outline:0}.bcs-otp032-actions{display:flex;justify-content:flex-end;gap:10px}.bcs-otp032-error{color:#b91c1c;background:#fef2f2;padding:10px 12px;border-radius:8px;margin-top:12px}.bcs-otp032-success{color:#166534;background:#f0fdf4;padding:10px 12px;border-radius:8px;margin-top:12px}
        </style>
        <script>
        (()=>{
            let bypass=false;
            const nonce='<?php echo esc_js($nonce); ?>';

            const regId=el=>{
                const box=el.closest('[data-registration-id],[data-id],form,tr');
                const vals=[el.dataset.registrationId,box&&box.dataset.registrationId,box&&box.dataset.id];
                if(box){for(const n of ['registration_id','id']){const i=box.querySelector('[name="'+n+'"]');if(i)vals.push(i.value)}}
                try{const u=new URL(el.getAttribute('href')||'',location.href);vals.push(u.searchParams.get('registration_id'),u.searchParams.get('id'))}catch(e){}
                return parseInt(vals.find(v=>/^\d+$/.test(v||''))||'0',10);
            };

            const post=async data=>{
                const fd=new FormData();
                Object.entries(data).forEach(([k,v])=>fd.append(k,v));
                const r=await fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:fd});
                return r.json();
            };

            const openModal=(isList)=>{
                document.querySelector('.bcs-otp032-backdrop')?.remove();
                const d=document.createElement('div');
                d.className='bcs-otp032-backdrop';
                d.innerHTML='<div class="bcs-otp032-modal '+(isList?'is-list':'')+'" role="dialog" aria-modal="true"><div class="bcs-otp032-head"><div class="bcs-otp032-icon">✉</div><div><h2>Potwierdzenie Organizatora</h2><p>Autoryzacja wysłania umowy kodem SMS</p></div></div><div class="bcs-otp032-body"><div class="bcs-otp032-status" data-status>Wysyłamy kod na numer zapisany w danych Organizatora…</div><div class="bcs-otp032-code" data-code hidden>'+Array.from({length:6},(_,i)=>'<input inputmode="numeric" maxlength="1" autocomplete="one-time-code" aria-label="Cyfra '+(i+1)+'">').join('')+'</div><div class="bcs-otp032-error" data-error hidden></div><div class="bcs-otp032-success" data-success hidden></div><div class="bcs-otp032-actions"><button type="button" class="button" data-cancel>Anuluj</button><button type="button" class="button button-primary" data-verify disabled>Potwierdź i wyślij umowę</button></div></div></div>';
                document.body.appendChild(d);
                return d;
            };

            document.addEventListener('click',async e=>{
                const button=e.target.closest('button,a,input[type=submit]');
                if(!button||bypass)return;
                const text=(button.innerText||button.value||'').trim().toLowerCase();
                if(!text.includes('wyślij umow'))return;
                const id=regId(button);
                if(!id)return;

                e.preventDefault();
                e.stopImmediatePropagation();

                const originalButton=button;
                const registrationId=id;
                const isList=!!button.closest('tr[data-id],table[data-bcs-live-table],.bcs-table');
                const modal=openModal(isList);
                const status=modal.querySelector('[data-status]');
                const error=modal.querySelector('[data-error]');
                const success=modal.querySelector('[data-success]');
                const codeWrap=modal.querySelector('[data-code]');
                const verify=modal.querySelector('[data-verify]');
                const cancel=modal.querySelector('[data-cancel]');
                cancel.onclick=()=>modal.remove();

                let sent;
                try {
                    sent=await post({action:'bcs_organizer_agreement_otp_send_030',nonce,registration_id:registrationId});
                } catch(err) {
                    status.textContent='Nie można wysłać kodu.';
                    error.hidden=false;
                    error.textContent='Błąd połączenia z serwerem.';
                    return;
                }
                if(!sent.success){
                    status.textContent='Nie można wysłać kodu.';
                    error.hidden=false;
                    error.textContent=sent.data&&sent.data.message||'Wystąpił błąd.';
                    return;
                }

                status.innerHTML='<strong>'+sent.data.organizer+'</strong><br>Kod wysłano na '+sent.data.phone+'. Wpisz 6 cyfr poniżej.';
                codeWrap.hidden=false;
                const inputs=[...codeWrap.querySelectorAll('input')];
                inputs[0].focus();
                const updateState=()=>{verify.disabled=inputs.some(x=>!x.value)};
                inputs.forEach((inp,i)=>{
                    inp.addEventListener('input',()=>{
                        const digits=inp.value.replace(/\D/g,'');
                        if(digits.length>1){
                            digits.slice(0,6).split('').forEach((digit,offset)=>{if(inputs[i+offset])inputs[i+offset].value=digit});
                            inputs[Math.min(i+digits.length,5)].focus();
                        } else {
                            inp.value=digits.slice(0,1);
                            if(inp.value&&inputs[i+1])inputs[i+1].focus();
                        }
                        updateState();
                    });
                    inp.addEventListener('keydown',ev=>{if(ev.key==='Backspace'&&!inp.value&&inputs[i-1])inputs[i-1].focus()});
                    inp.addEventListener('paste',ev=>{
                        const digits=(ev.clipboardData.getData('text')||'').replace(/\D/g,'').slice(0,6);
                        if(!digits)return;
                        ev.preventDefault();
                        digits.split('').forEach((digit,index)=>{if(inputs[index])inputs[index].value=digit});
                        inputs[Math.min(digits.length,6)-1].focus();
                        updateState();
                    });
                });

                verify.onclick=async()=>{
                    verify.disabled=true;
                    error.hidden=true;
                    success.hidden=true;
                    const code=inputs.map(x=>x.value).join('');
                    let checked;
                    try {
                        checked=await post({action:'bcs_organizer_agreement_otp_verify_030',nonce,registration_id:registrationId,code});
                    } catch(err) {
                        error.hidden=false;
                        error.textContent='Błąd połączenia podczas weryfikacji kodu.';
                        verify.disabled=false;
                        return;
                    }
                    if(!checked.success){
                        error.hidden=false;
                        error.textContent=checked.data&&checked.data.message||'Kod jest nieprawidłowy.';
                        verify.disabled=false;
                        inputs.forEach(i=>i.value='');
                        inputs[0].focus();
                        return;
                    }
                    success.hidden=false;
                    success.textContent=checked.data.message||'Kod został potwierdzony.';
                    status.innerHTML='<strong>Potwierdzono tożsamość Organizatora.</strong><br>Umowa jest przekazywana do rodzica.';
                    verify.textContent='Wysyłanie…';
                    cancel.disabled=true;
                    setTimeout(()=>{
                        modal.remove();
                        bypass=true;
                        originalButton.click();
                        setTimeout(()=>bypass=false,1800);
                    },500);
                };
            },true);
        })();
        </script>
        <?php
    }
}
