<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_045 {
    public static function init(): void {
        // Wysłanie umowy do rodzica nie wymaga już podpisu Organizatora.
        remove_action('wp_ajax_bcs_card_action_02021', ['BCS_Release_029_Gate', 'gate_card_send'], 0);
        remove_action('wp_ajax_bcs_list_quick_action_02010', ['BCS_Release_029_Gate', 'gate_list_send'], 0);
        remove_action('admin_footer', ['BCS_Release_029_Gate', 'list_button_script'], 1);
        remove_action('admin_footer', ['BCS_Release_029', 'admin_footer_script']);
        remove_action('admin_footer', ['BCS_Release_041', 'admin_footer_script'], 99);

        add_action('admin_footer', [__CLASS__, 'admin_footer_script'], 120);
    }

    public static function admin_footer_script(): void {
        if (!current_user_can('manage_options')) return;
        $page = sanitize_key($_GET['page'] ?? '');
        if ($page !== 'bcs-registrations') return;
        $nonce = wp_create_nonce('bcs_admin');
        ?>
        <script>
        (()=>{
            const nonce=<?php echo wp_json_encode($nonce); ?>;
            function idOf(el){
                const box=el.closest('[data-registration-id],[data-id],tr,form');
                const vals=[el.dataset.registrationId,box&&box.dataset.registrationId,box&&box.dataset.id];
                if(box){for(const n of ['registration_id','id']){const i=box.querySelector('[name="'+n+'"]');if(i)vals.push(i.value)}}
                const href=el.getAttribute&&el.getAttribute('href');
                if(href){try{const u=new URL(href,location.href);vals.push(u.searchParams.get('registration_id'),u.searchParams.get('view'),u.searchParams.get('id'));}catch(e){}}
                return parseInt(vals.find(v=>/^\d+$/.test(v||''))||'0',10);
            }
            async function request(action,data={}){
                const fd=new FormData();fd.append('action',action);fd.append('nonce',nonce);
                Object.entries(data).forEach(([k,v])=>Array.isArray(v)?v.forEach(x=>fd.append(k+'[]',x)):fd.append(k,v));
                const r=await fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:fd});
                return r.json();
            }
            function hosts(){return [...document.querySelectorAll('[data-registration-id],[data-id],tr,form,.bcs-quick-actions,.bcs-crm-buttons')];}
            async function install(){
                const boxes=hosts();
                const ids=[...new Set(boxes.map(idOf).filter(Boolean))];
                const urlId=parseInt(new URL(location.href).searchParams.get('view')||'0',10);if(urlId)ids.push(urlId);
                if(!ids.length)return;
                const j=await request('bcs_041_signature_state',{registration_ids:[...new Set(ids)]});
                if(!j.success)return;
                const eligible=new Set((j.data.eligible||[]).map(Number));
                eligible.forEach(id=>{
                    let box=boxes.find(x=>idOf(x)===id);
                    let host=box&&(box.querySelector('.bcs-quick-actions,.bcs-crm-buttons,.quick-actions,.actions,td:last-child')||box);
                    if(!host&&urlId===id)host=document.querySelector('.bcs-quick-actions .bcs-crm-buttons,.bcs-quick-actions,.bcs-crm-buttons');
                    if(!host||host.querySelector('.bcs-org-sign-045'))return;
                    const b=document.createElement('button');
                    b.type='button';b.className='button button-primary bcs-org-sign-045';b.dataset.registrationId=id;b.textContent='Podpisz umowę!';
                    host.appendChild(b);
                });
            }
            document.addEventListener('click',async e=>{
                const b=e.target.closest('.bcs-org-sign-045');if(!b)return;
                const id=idOf(b)||parseInt(b.dataset.registrationId||'0',10);if(!id)return;
                const phone=prompt('Podaj numer telefonu Organizatora, na który wysłać kod SMS do podpisania umowy:');
                if(!phone)return;
                b.disabled=true;
                try{
                    let j=await request('bcs_organizer_agreement_otp_send',{registration_id:id,phone});
                    if(!j.success){alert(j.data&&j.data.message||'Nie udało się wysłać kodu SMS.');return;}
                    const code=prompt('Wpisz 6-cyfrowy kod SMS wysłany na '+(j.data.phone||'numer Organizatora')+':');
                    if(!code)return;
                    j=await request('bcs_organizer_agreement_otp_verify',{registration_id:id,code});
                    if(!j.success){alert(j.data&&j.data.message||'Kod jest nieprawidłowy.');return;}
                    alert(j.data.message||'Umowa została podpisana przez Organizatora.');
                    location.reload();
                } finally {b.disabled=false;}
            });
            install();
        })();
        </script>
        <?php
    }
}
