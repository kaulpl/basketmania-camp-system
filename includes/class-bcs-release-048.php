<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_048 {
    public static function init(): void {
        // Skrypt musi zostać zarejestrowany przed listenerem 0.46,
        // który korzystał z natywnych okien prompt().
        add_action('admin_head', [__CLASS__, 'admin_head'], 0);
    }

    public static function admin_head(): void {
        if (!current_user_can('manage_options')) return;
        if (sanitize_key($_GET['page'] ?? '') !== 'bcs-registrations') return;
        $nonce = wp_create_nonce('bcs_046');
        ?>
        <style>
            body.bcs-org-modal-open-048{overflow:hidden}
            .bcs-org-modal-048[hidden]{display:none!important}
            .bcs-org-modal-048{position:fixed;inset:0;z-index:1000000;display:flex;align-items:center;justify-content:center;padding:20px;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
            .bcs-org-modal-048__backdrop{position:absolute;inset:0;background:rgba(15,23,42,.68);backdrop-filter:blur(3px)}
            .bcs-org-modal-048__dialog{position:relative;z-index:1;width:min(470px,100%);overflow:hidden;border:1px solid rgba(255,255,255,.5);border-radius:20px;background:#fff;box-shadow:0 28px 90px rgba(15,23,42,.38)}
            .bcs-org-modal-048__head{position:relative;padding:26px 68px 24px 26px;background:linear-gradient(135deg,#172033 0%,#293056 64%,#f97316 150%);color:#fff}
            .bcs-org-modal-048__head-inner{display:flex;align-items:center;gap:15px}
            .bcs-org-modal-048__icon{display:grid;place-items:center;width:50px;height:50px;flex:0 0 50px;border-radius:15px;background:rgba(255,255,255,.15);font-size:24px;box-shadow:inset 0 0 0 1px rgba(255,255,255,.16)}
            .bcs-org-modal-048__head h2{margin:0;color:#fff;font-size:22px;line-height:1.2}
            .bcs-org-modal-048__head p{margin:5px 0 0;color:rgba(255,255,255,.82);font-size:13px}
            .bcs-org-modal-048__close{position:absolute;right:16px;top:15px;width:38px;height:38px;border:0;border-radius:11px;background:rgba(255,255,255,.12);color:#fff;font-size:28px;line-height:1;cursor:pointer}
            .bcs-org-modal-048__close:hover,.bcs-org-modal-048__close:focus{background:rgba(255,255,255,.22);outline:none}
            .bcs-org-modal-048__body{padding:26px}
            .bcs-org-modal-048__status{padding:14px 15px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;color:#334155;line-height:1.5}
            .bcs-org-modal-048__status strong{color:#172033}
            .bcs-org-modal-048__spinner{display:inline-block;width:16px;height:16px;margin-right:8px;border:2px solid #fed7aa;border-top-color:#f97316;border-radius:50%;vertical-align:-3px;animation:bcs-org-spin-048 .75s linear infinite}
            @keyframes bcs-org-spin-048{to{transform:rotate(360deg)}}
            .bcs-org-modal-048__label{display:block;margin:22px 0 8px;color:#172033;font-weight:800}
            .bcs-org-modal-048__code{width:100%;height:64px;padding:10px 14px;border:2px solid #cbd5e1;border-radius:12px;background:#fff;color:#172033;font-size:28px;font-weight:900;letter-spacing:.34em;text-align:center;box-sizing:border-box;transition:.15s ease}
            .bcs-org-modal-048__code:focus{border-color:#f97316;box-shadow:0 0 0 4px rgba(249,115,22,.14);outline:none}
            .bcs-org-modal-048__timer{margin:14px 0 0;padding:11px 13px;border-radius:10px;background:#fff7ed;color:#9a3412;text-align:center;font-weight:700}
            .bcs-org-modal-048__timer strong{font-variant-numeric:tabular-nums}
            .bcs-org-modal-048__message{margin-top:14px;padding:11px 13px;border-radius:10px;background:#fef2f2;color:#b91c1c;font-weight:700;line-height:1.4}
            .bcs-org-modal-048__message.is-success{background:#ecfdf3;color:#047857}
            .bcs-org-modal-048__actions{display:flex;justify-content:flex-end;gap:10px;margin-top:22px}
            .bcs-org-modal-048__actions .button{min-height:42px;padding:5px 17px;border-radius:9px}
            .bcs-org-modal-048__verify{border-color:#f97316!important;background:#f97316!important;color:#fff!important;font-weight:800!important}
            .bcs-org-modal-048__verify:hover,.bcs-org-modal-048__verify:focus{border-color:#c2410c!important;background:#c2410c!important}
            .bcs-org-modal-048__verify:disabled{opacity:.48;cursor:not-allowed}
            .bcs-org-modal-048__security{display:flex;align-items:center;gap:7px;margin-top:17px;color:#64748b;font-size:12px}
            .bcs-org-modal-048__security:before{content:'✓';display:grid;place-items:center;width:18px;height:18px;border-radius:50%;background:#16a34a;color:#fff;font-size:11px;font-weight:900}
            @media(max-width:600px){.bcs-org-modal-048{padding:10px}.bcs-org-modal-048__head{padding:22px 58px 21px 19px}.bcs-org-modal-048__body{padding:21px 18px}.bcs-org-modal-048__code{font-size:24px}.bcs-org-modal-048__actions{flex-direction:column-reverse}.bcs-org-modal-048__actions .button{width:100%;justify-content:center}}
        </style>
        <script>
        (()=>{
            const nonce=<?php echo wp_json_encode($nonce); ?>;
            const ajax=window.ajaxurl||<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            const ttlSeconds=600;
            let activeButton=null,activeId=0,timer=null,expiresAt=0,busy=false;

            function idOf(element){
                if(!element)return 0;
                const values=[];
                if(element.dataset)values.push(element.dataset.registrationId,element.dataset.id);
                const box=element.closest&&element.closest('tr[data-id],tr[data-registration-id],[data-registration-id],[data-id],form');
                if(box){
                    values.push(box.dataset&&box.dataset.registrationId,box.dataset&&box.dataset.id);
                    for(const name of ['registration_id','id']){
                        const input=box.querySelector&&box.querySelector('[name="'+name+'"]');
                        if(input)values.push(input.value);
                    }
                }
                const href=element.getAttribute&&element.getAttribute('href');
                if(href){try{const url=new URL(href,location.href);values.push(url.searchParams.get('registration_id'),url.searchParams.get('view'),url.searchParams.get('id'));}catch(error){}}
                return parseInt(values.find(value=>/^\d+$/.test(value||''))||'0',10);
            }

            async function post(action,data={}){
                const form=new FormData();
                form.append('action',action);form.append('nonce',nonce);
                Object.entries(data).forEach(([key,value])=>form.append(key,value));
                const response=await fetch(ajax,{method:'POST',credentials:'same-origin',cache:'no-store',body:form});
                return response.json();
            }

            function modal(){
                let root=document.querySelector('#bcs-org-modal-048');
                if(root)return root;
                root=document.createElement('div');
                root.id='bcs-org-modal-048';
                root.className='bcs-org-modal-048';
                root.hidden=true;
                root.innerHTML=`
                    <div class="bcs-org-modal-048__backdrop" data-bcs-org-close-048></div>
                    <div class="bcs-org-modal-048__dialog" role="dialog" aria-modal="true" aria-labelledby="bcs-org-modal-title-048">
                        <div class="bcs-org-modal-048__head">
                            <button type="button" class="bcs-org-modal-048__close" data-bcs-org-close-048 aria-label="Zamknij">×</button>
                            <div class="bcs-org-modal-048__head-inner">
                                <span class="bcs-org-modal-048__icon" aria-hidden="true">✉</span>
                                <div><h2 id="bcs-org-modal-title-048">Podpis Organizatora</h2><p>Potwierdzenie umowy bezpiecznym kodem SMS</p></div>
                            </div>
                        </div>
                        <div class="bcs-org-modal-048__body">
                            <div class="bcs-org-modal-048__status" data-bcs-org-status-048></div>
                            <div data-bcs-org-code-wrap-048 hidden>
                                <label class="bcs-org-modal-048__label" for="bcs-org-code-048">Kod SMS</label>
                                <input id="bcs-org-code-048" class="bcs-org-modal-048__code" maxlength="6" inputmode="numeric" autocomplete="one-time-code" aria-describedby="bcs-org-timer-048">
                                <div id="bcs-org-timer-048" class="bcs-org-modal-048__timer">Pozostały czas: <strong data-bcs-org-countdown-048>10:00</strong></div>
                            </div>
                            <div class="bcs-org-modal-048__message" data-bcs-org-message-048 hidden></div>
                            <div class="bcs-org-modal-048__security">Kod zostanie zweryfikowany wyłącznie dla tej umowy i właściwego Organizatora.</div>
                            <div class="bcs-org-modal-048__actions">
                                <button type="button" class="button" data-bcs-org-close-048>Anuluj</button>
                                <button type="button" class="button button-primary bcs-org-modal-048__verify" data-bcs-org-verify-048 disabled>Potwierdź podpis umowy</button>
                            </div>
                        </div>
                    </div>`;
                document.body.appendChild(root);
                root.querySelectorAll('[data-bcs-org-close-048]').forEach(button=>button.addEventListener('click',close));
                root.querySelector('#bcs-org-code-048').addEventListener('input',event=>{
                    event.target.value=event.target.value.replace(/\D/g,'').slice(0,6);
                    root.querySelector('[data-bcs-org-verify-048]').disabled=event.target.value.length!==6||busy;
                });
                root.querySelector('[data-bcs-org-verify-048]').addEventListener('click',verify);
                return root;
            }

            function setMessage(text,type='error'){
                const box=modal().querySelector('[data-bcs-org-message-048]');
                box.hidden=!text;box.textContent=text||'';box.classList.toggle('is-success',type==='success');
            }

            function setStatus(html){modal().querySelector('[data-bcs-org-status-048]').innerHTML=html;}

            function setButtonBusy(isBusy){
                if(!activeButton)return;
                if(isBusy){
                    activeButton.dataset.bcs048Original=activeButton.textContent||'';
                    activeButton.disabled=true;activeButton.textContent='Podpisywanie…';
                }else{
                    activeButton.disabled=false;
                    activeButton.textContent=activeButton.dataset.bcs048Original||'Podpisz umowę przez SMS';
                    delete activeButton.dataset.bcs048Original;
                }
            }

            function open(){
                const root=modal();
                root.hidden=false;document.body.classList.add('bcs-org-modal-open-048');
                root.querySelector('[data-bcs-org-code-wrap-048]').hidden=true;
                root.querySelector('#bcs-org-code-048').value='';
                root.querySelector('[data-bcs-org-verify-048]').hidden=false;
                root.querySelector('[data-bcs-org-verify-048]').disabled=true;
                setMessage('');
            }

            function close(){
                if(busy)return;
                clearInterval(timer);timer=null;
                const root=modal();root.hidden=true;document.body.classList.remove('bcs-org-modal-open-048');
                setButtonBusy(false);activeButton=null;activeId=0;
            }

            function tick(){
                const left=Math.max(0,Math.ceil((expiresAt-Date.now())/1000));
                const minutes=String(Math.floor(left/60)).padStart(2,'0');
                const seconds=String(left%60).padStart(2,'0');
                const countdown=modal().querySelector('[data-bcs-org-countdown-048]');
                if(countdown)countdown.textContent=minutes+':'+seconds;
                if(left<=0){
                    clearInterval(timer);timer=null;
                    modal().querySelector('[data-bcs-org-verify-048]').disabled=true;
                    setMessage('Kod wygasł. Zamknij okno i ponownie wybierz „Podpisz umowę przez SMS”.');
                }
            }

            function startTimer(){
                clearInterval(timer);expiresAt=Date.now()+ttlSeconds*1000;tick();timer=setInterval(tick,1000);
            }

            async function begin(button,id){
                activeButton=button;activeId=id;open();setButtonBusy(true);busy=true;
                setStatus('<span class="bcs-org-modal-048__spinner" aria-hidden="true"></span>Wysyłamy kod SMS na numer zapisany przy Organizatorze…');
                try{
                    const result=await post('bcs_046_organizer_otp_send',{registration_id:id});
                    if(!result.success){
                        setStatus('<strong>Nie udało się wysłać kodu.</strong>');
                        setMessage(result.data&&result.data.message||'Wystąpił błąd podczas wysyłania wiadomości SMS.');
                        return;
                    }
                    setStatus('<strong>'+escapeHtml(result.data.organizer||'Organizator')+'</strong><br>Kod SMS wysłano na numer '+escapeHtml(result.data.phone||'zapisany w systemie')+'.');
                    const root=modal();root.querySelector('[data-bcs-org-code-wrap-048]').hidden=false;
                    startTimer();setTimeout(()=>root.querySelector('#bcs-org-code-048').focus(),60);
                }catch(error){
                    setStatus('<strong>Błąd połączenia.</strong>');
                    setMessage('Nie udało się połączyć z systemem wysyłki SMS. Spróbuj ponownie.');
                }finally{
                    busy=false;
                    const code=modal().querySelector('#bcs-org-code-048');
                    modal().querySelector('[data-bcs-org-verify-048]').disabled=code.value.length!==6;
                    if(modal().querySelector('[data-bcs-org-code-wrap-048]').hidden)setButtonBusy(false);
                }
            }

            async function verify(){
                const root=modal(),code=root.querySelector('#bcs-org-code-048').value;
                if(code.length!==6){setMessage('Wpisz pełny 6-cyfrowy kod SMS.');return;}
                busy=true;root.querySelector('[data-bcs-org-verify-048]').disabled=true;setMessage('Weryfikujemy kod i podpisujemy umowę…','success');
                try{
                    const result=await post('bcs_046_organizer_otp_verify',{registration_id:activeId,code});
                    if(!result.success){
                        setMessage(result.data&&result.data.message||'Kod jest nieprawidłowy.');
                        root.querySelector('[data-bcs-org-verify-048]').disabled=false;
                        root.querySelector('#bcs-org-code-048').focus();
                        return;
                    }
                    clearInterval(timer);timer=null;
                    setStatus('<strong>Umowa została podpisana.</strong><br>Podpis Organizatora został potwierdzony kodem SMS.');
                    setMessage(result.data&&result.data.message||'Proces podpisania umowy został zakończony.','success');
                    root.querySelector('[data-bcs-org-code-wrap-048]').hidden=true;
                    root.querySelector('[data-bcs-org-verify-048]').hidden=true;
                    setTimeout(()=>location.reload(),1000);
                }catch(error){
                    setMessage('Błąd połączenia podczas weryfikacji kodu.');
                    root.querySelector('[data-bcs-org-verify-048]').disabled=false;
                }finally{busy=false;}
            }

            function escapeHtml(value){
                const span=document.createElement('span');span.textContent=String(value||'');return span.innerHTML;
            }

            // Listener jest celowo rejestrowany przed skryptem 0.46 i zatrzymuje
            // jego obsługę prompt(), pozostawiając ten sam backend OTP.
            document.addEventListener('click',event=>{
                const button=event.target.closest&&event.target.closest('.bcs-org-sign-046');
                if(!button)return;
                event.preventDefault();event.stopPropagation();event.stopImmediatePropagation();
                const id=idOf(button)||parseInt(button.dataset.registrationId||'0',10);
                if(!id)return;
                begin(button,id);
            },true);

            document.addEventListener('keydown',event=>{
                const root=document.querySelector('#bcs-org-modal-048');
                if(event.key==='Escape'&&root&&!root.hidden&&!busy)close();
                if(event.key==='Enter'&&root&&!root.hidden&&document.activeElement===root.querySelector('#bcs-org-code-048')){
                    event.preventDefault();
                    if(root.querySelector('#bcs-org-code-048').value.length===6&&!busy)verify();
                }
            });
        })();
        </script>
        <?php
    }
}
