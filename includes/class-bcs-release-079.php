<?php
if (!defined('ABSPATH')) exit;

/**
 * Wersja 0.79 – AutoFill kodów OTP w Safari / macOS i na urządzeniach Apple.
 *
 * - oznacza pole OTP rodzica jako autocomplete="one-time-code",
 * - zastępuje prompt() Organizatora prawdziwym polem formularza OTP,
 * - pozostawia wysyłkę, limity i dowód podpisu z 0.78 bez zmian.
 */
final class BCS_Release_079 {
    public static function init(): void {
        // Do formularza rodzica dokładamy semantykę OTP bez przepisywania całego widoku.
        add_filter('do_shortcode_tag', [__CLASS__, 'enhance_parent_otp_markup'], 20, 4);

        // Rejestrujemy listener przed skryptem 0.46 (priority 1). Przejmuje wyłącznie
        // kliknięcie podpisu Organizatora, dzięki czemu historyczny prompt() nie uruchomi się.
        add_action('admin_head', [__CLASS__, 'admin_otp_autofill_ui'], 0);
    }

    /**
     * @param mixed $output
     * @param mixed $tag
     * @param mixed $attr
     * @param mixed $m
     * @return mixed
     */
    public static function enhance_parent_otp_markup($output, $tag, $attr, $m) {
        if ($tag !== 'basketmania_portal' || !is_string($output) || strpos($output, 'id="bcs-code"') === false) {
            return $output;
        }

        $legacy = '<input id="bcs-code" maxlength="6" inputmode="numeric">';
        $enhanced = '<input id="bcs-code" name="bcs_otp_code" type="text" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code" autocapitalize="off" spellcheck="false" enterkeyhint="done">';
        return str_replace($legacy, $enhanced, $output);
    }

    public static function admin_otp_autofill_ui(): void {
        if (!current_user_can('manage_options')) return;
        $page = sanitize_key($_GET['page'] ?? '');
        if ($page !== 'bcs-registrations') return;

        $nonce = wp_create_nonce('bcs_046');
        ?>
        <style>
            .bcs-otp079-open{overflow:hidden}
            .bcs-otp079[hidden]{display:none!important}
            .bcs-otp079{position:fixed;inset:0;z-index:100100;display:flex;align-items:center;justify-content:center;padding:24px}
            .bcs-otp079-backdrop{position:absolute;inset:0;background:rgba(17,24,39,.55)}
            .bcs-otp079-dialog{position:relative;z-index:1;width:min(460px,calc(100vw - 32px));background:#fff;border-radius:14px;box-shadow:0 24px 80px rgba(0,0,0,.28);padding:24px}
            .bcs-otp079-dialog h2{margin:0 36px 8px 0;font-size:22px;line-height:1.25}
            .bcs-otp079-dialog p{margin:0 0 18px;color:#50575e}
            .bcs-otp079-close{position:absolute;right:14px;top:12px;border:0;background:transparent;font-size:28px;line-height:1;cursor:pointer;color:#50575e}
            .bcs-otp079-label{display:block;font-weight:600;margin-bottom:16px}
            .bcs-otp079-code{display:block;width:100%;box-sizing:border-box;margin-top:7px;padding:12px 14px!important;font-size:28px!important;line-height:1.2!important;letter-spacing:.25em;text-align:center;font-variant-numeric:tabular-nums}
            .bcs-otp079-note{display:block;margin:-6px 0 18px;color:#646970;line-height:1.45}
            .bcs-otp079-actions{display:flex;gap:10px;justify-content:flex-end;align-items:center}
            .bcs-otp079-error{min-height:20px;margin:12px 0 0!important;color:#b32d2e!important;font-weight:600}
            @media(max-width:600px){.bcs-otp079{padding:12px}.bcs-otp079-dialog{padding:20px}.bcs-otp079-actions{align-items:stretch;flex-direction:column-reverse}.bcs-otp079-actions .button{width:100%;text-align:center;justify-content:center}}
        </style>
        <script>
        (()=>{
            const nonce=<?php echo wp_json_encode($nonce); ?>;
            const ajax=window.ajaxurl||<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;

            function registrationId(button){
                const vals=[];
                if(button&&button.dataset)vals.push(button.dataset.registrationId,button.dataset.id);
                const row=button&&button.closest&&button.closest('tr[data-id],tr[data-registration-id],[data-registration-id],[data-id],form');
                if(row){
                    vals.push(row.dataset&&row.dataset.registrationId,row.dataset&&row.dataset.id);
                    for(const name of ['registration_id','id']){
                        const input=row.querySelector&&row.querySelector('[name="'+name+'"]');
                        if(input)vals.push(input.value);
                    }
                }
                const href=button&&button.getAttribute&&button.getAttribute('href');
                if(href){
                    try{
                        const url=new URL(href,location.href);
                        vals.push(url.searchParams.get('registration_id'),url.searchParams.get('view'),url.searchParams.get('id'));
                    }catch(e){}
                }
                return parseInt(vals.find(value=>/^\d+$/.test(value||''))||'0',10);
            }

            async function post(action,data={}){
                const fd=new FormData();
                fd.append('action',action);fd.append('nonce',nonce);
                Object.entries(data).forEach(([key,value])=>fd.append(key,value));
                const response=await fetch(ajax,{method:'POST',credentials:'same-origin',cache:'no-store',body:fd});
                return response.json();
            }

            function ensureModal(){
                let modal=document.querySelector('#bcs-org-otp-modal-079');
                if(modal)return modal;
                modal=document.createElement('div');
                modal.id='bcs-org-otp-modal-079';
                modal.className='bcs-otp079';
                modal.hidden=true;
                modal.innerHTML=`
                    <div class="bcs-otp079-backdrop" data-bcs079-close></div>
                    <div class="bcs-otp079-dialog" role="dialog" aria-modal="true" aria-labelledby="bcs-org-otp-title-079">
                        <button type="button" class="bcs-otp079-close" data-bcs079-close aria-label="Zamknij">×</button>
                        <h2 id="bcs-org-otp-title-079">Kod SMS Organizatora</h2>
                        <p id="bcs-org-otp-recipient-079"></p>
                        <form id="bcs-org-otp-form-079" autocomplete="on">
                            <label class="bcs-otp079-label" for="bcs-org-otp-code-079">6-cyfrowy kod SMS
                                <input id="bcs-org-otp-code-079" class="bcs-otp079-code" name="bcs_organizer_otp_code" type="text" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code" autocapitalize="off" spellcheck="false" enterkeyhint="done" required>
                            </label>
                            <small class="bcs-otp079-note">W Safari na Macu kod odebrany na iPhonie może pojawić się jako propozycja AutoFill. Na iPhonie musi być włączone przekazywanie wiadomości SMS na tego Maca.</small>
                            <div class="bcs-otp079-actions">
                                <button type="button" class="button" data-bcs079-close>Anuluj</button>
                                <button type="submit" class="button button-primary">Potwierdź podpis</button>
                            </div>
                            <p class="bcs-otp079-error" id="bcs-org-otp-error-079" role="alert"></p>
                        </form>
                    </div>`;
                document.body.appendChild(modal);
                return modal;
            }

            function requestCode(recipient){
                return new Promise(resolve=>{
                    const modal=ensureModal();
                    const form=modal.querySelector('#bcs-org-otp-form-079');
                    const code=modal.querySelector('#bcs-org-otp-code-079');
                    const error=modal.querySelector('#bcs-org-otp-error-079');
                    const recipientNode=modal.querySelector('#bcs-org-otp-recipient-079');
                    let done=false;
                    const sanitizeInput=()=>{code.value=code.value.replace(/\D/g,'').slice(0,6);error.textContent='';};
                    const finish=value=>{
                        if(done)return;done=true;
                        modal.hidden=true;
                        document.body.classList.remove('bcs-otp079-open');
                        form.removeEventListener('submit',submit);
                        code.removeEventListener('input',sanitizeInput);
                        modal.querySelectorAll('[data-bcs079-close]').forEach(node=>node.removeEventListener('click',cancel));
                        document.removeEventListener('keydown',escape);
                        resolve(value);
                    };
                    const cancel=event=>{event&&event.preventDefault();finish(null);};
                    const escape=event=>{if(event.key==='Escape'){event.preventDefault();finish(null);}};
                    const submit=event=>{
                        event.preventDefault();sanitizeInput();
                        if(code.value.length!==6){error.textContent='Wpisz pełny 6-cyfrowy kod SMS.';code.focus();return;}
                        finish(code.value);
                    };

                    recipientNode.textContent=recipient||'Kod został wysłany na numer Organizatora.';
                    code.value='';error.textContent='';
                    code.addEventListener('input',sanitizeInput);
                    form.addEventListener('submit',submit);
                    modal.querySelectorAll('[data-bcs079-close]').forEach(node=>node.addEventListener('click',cancel));
                    document.addEventListener('keydown',escape);
                    modal.hidden=false;
                    document.body.classList.add('bcs-otp079-open');
                    window.setTimeout(()=>code.focus({preventScroll:true}),80);
                });
            }

            document.addEventListener('click',async event=>{
                const button=event.target.closest&&event.target.closest('.bcs-org-sign-046');
                if(!button)return;

                // Ten listener jest wcześniejszy niż 0.46 i zastępuje wyłącznie jego prompt().
                event.preventDefault();event.stopPropagation();event.stopImmediatePropagation();

                const id=registrationId(button);
                if(!id||button.dataset.bcs079Busy==='1')return;
                button.dataset.bcs079Busy='1';
                const original=button.textContent;
                button.disabled=true;button.textContent='Wysyłanie kodu…';

                try{
                    let result=await post('bcs_046_organizer_otp_send',{registration_id:id});
                    if(!result.success){alert(result.data&&result.data.message||'Nie udało się wysłać kodu SMS do Organizatora.');return;}

                    button.textContent='Oczekiwanie na kod…';
                    const recipient='Kod wysłano do '+(result.data.organizer||'Organizatora')+' na numer '+(result.data.phone||'zapisany w systemie')+'.';
                    const code=await requestCode(recipient);
                    if(!code)return;

                    button.textContent='Weryfikacja kodu…';
                    result=await post('bcs_046_organizer_otp_verify',{registration_id:id,code});
                    if(!result.success){alert(result.data&&result.data.message||'Kod jest nieprawidłowy.');return;}
                    alert(result.data.message||'Umowa została podpisana przez Organizatora.');
                    location.reload();
                }catch(error){
                    alert('Wystąpił błąd połączenia podczas podpisywania umowy.');
                }finally{
                    button.dataset.bcs079Busy='0';
                    button.disabled=false;
                    button.textContent=original||'Podpisz umowę przez SMS';
                }
            },true);
        })();
        </script>
        <?php
    }
}
