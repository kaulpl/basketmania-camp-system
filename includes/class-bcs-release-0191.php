<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_0191 {
    public static function init(): void {
        add_action('admin_head', [self::class, 'styles'], 500);
        add_action('admin_footer', [self::class, 'scripts'], 500);
    }

    private static function is_registration_card(): bool {
        return is_admin()
            && sanitize_key(wp_unslash($_GET['page'] ?? '')) === 'bcs-registrations'
            && absint($_GET['view'] ?? 0) > 0;
    }

    public static function styles(): void {
        if (!self::is_registration_card()) return;
        ?>
        <style>
            .bcs-crm-layout{display:grid!important;grid-template-columns:minmax(0,1fr) 340px!important;gap:22px!important;align-items:start!important}
            .bcs-crm-layout>main{min-width:0!important}
            .bcs-crm-layout>aside{display:block!important;min-width:0!important;position:relative!important}
            .bcs-crm-layout>aside>.bcs-quick-actions{position:sticky!important;top:46px!important;width:auto!important;margin:0 0 18px!important}
            .bcs-handling-section-0190{display:none!important}
            .bcs-quick-actions .bcs-crm-action[data-bcs-action="task"]{display:none!important}
            .bcs-withdraw-agreement-0191{width:100%;justify-content:center;border-color:#d97706!important;color:#9a3412!important;background:#fff7ed!important}
            @media (max-width:1100px){.bcs-crm-layout{grid-template-columns:1fr!important}.bcs-crm-layout>aside>.bcs-quick-actions{position:static!important}}
        </style>
        <?php
    }

    public static function scripts(): void {
        if (!self::is_registration_card()) return;
        $nonce = wp_create_nonce('bcs_withdraw_agreement_0190');
        ?>
        <script>
        (function(){
            const ajaxUrl=<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            const nonce=<?php echo wp_json_encode($nonce); ?>;
            const registrationId=<?php echo (int) absint($_GET['view'] ?? 0); ?>;

            function showResult(message,ok){
                if(typeof window.bcsPopup0190==='function'){window.bcsPopup0190(message,ok);return;}
                const popup=document.getElementById('bcs-result-popup-0190');
                if(!popup){window.alert(message);return;}
                popup.className='bcs-result-popup-0190 '+(ok?'success':'error')+' show';
                const icon=popup.querySelector('.bcs-result-popup-0190__icon');
                const title=popup.querySelector('h3');
                if(icon)icon.textContent=ok?'✓':'×';
                if(title)title.textContent=message;
                setTimeout(()=>popup.classList.remove('show'),2000);
            }

            async function withdrawAgreement(button){
                button.disabled=true;
                try{
                    const response=await fetch(ajaxUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:new URLSearchParams({action:'bcs_withdraw_agreement_0190',nonce:nonce,registration_id:String(registrationId)})});
                    const json=await response.json();
                    if(!response.ok||!json.success)throw new Error(json?.data?.message||'Nie udało się wycofać umowy.');
                    showResult(json.data.message||'Umowa została wycofana.',true);
                    if(typeof window.bcsRefreshRegistrationCard02017==='function'){
                        await window.bcsRefreshRegistrationCard02017();
                    }else{
                        button.remove();
                    }
                }catch(error){showResult(error.message||'Nie udało się wycofać umowy.',false);button.disabled=false;}
            }

            function restoreQuickActions(){
                const layout=document.querySelector('.bcs-crm-layout');
                if(!layout)return;
                let aside=layout.querySelector(':scope > aside');
                if(!aside){aside=document.createElement('aside');layout.appendChild(aside);}

                const handling=document.querySelector('.bcs-handling-section-0190');
                let quick=document.querySelector('.bcs-quick-actions');
                if(handling&&quick&&handling.contains(quick))aside.prepend(quick);
                if(handling)handling.remove();
                if(!quick)return;
                if(quick.parentElement!==aside)aside.prepend(quick);

                quick.querySelectorAll('form').forEach(form=>{
                    const action=form.querySelector('[name="bcs_crm_action"]')?.value||form.querySelector('button[name="bcs_crm_action"]')?.value||'';
                    if(action==='task'||/dodaj zadanie/i.test(form.textContent)){form.remove();return;}
                    form.dataset.bcsAction=action;
                });

                const buttons=quick.querySelector('.bcs-crm-buttons');
                const phone=[...quick.querySelectorAll('form')].find(f=>{const a=f.querySelector('[name="bcs_crm_action"]')?.value||f.querySelector('button[name="bcs_crm_action"]')?.value;return a==='phone'||/wykonano telefon|notatka z rozmowy/i.test(f.textContent);});
                const note=[...quick.querySelectorAll('form')].find(f=>{const a=f.querySelector('[name="bcs_crm_action"]')?.value||f.querySelector('button[name="bcs_crm_action"]')?.value;return a==='note'||/dodaj notatkę/i.test(f.textContent);});
                if(phone)quick.appendChild(phone);
                if(note)quick.appendChild(note);

                const pending=[...quick.querySelectorAll('button,a,span')].some(el=>/przypomnij o podpisaniu umowy|umowa wysłana do podpisania/i.test(el.textContent||''));
                const accepted=[...quick.querySelectorAll('button,a,span')].some(el=>/umowa podpisana/i.test(el.textContent||''));
                if(pending&&!accepted&&!quick.querySelector('.bcs-withdraw-agreement-0191')){
                    const button=document.createElement('button');
                    button.type='button';
                    button.className='button bcs-withdraw-agreement-0191';
                    button.innerHTML='<span class="dashicons dashicons-undo"></span> Wycofaj umowę przed podpisem';
                    button.addEventListener('click',()=>withdrawAgreement(button));
                    if(buttons){
                        const reminder=[...buttons.children].find(el=>/przypomnij o podpisaniu umowy/i.test(el.textContent||''));
                        reminder?reminder.after(button):buttons.appendChild(button);
                    }else quick.insertBefore(button,phone||note||null);
                }
            }

            restoreQuickActions();
            window.bcsRestoreQuickActions0191=restoreQuickActions;
            setTimeout(restoreQuickActions,100);
        })();
        </script>
        <?php
    }
}
