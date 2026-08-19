<?php
if (!defined('ABSPATH')) exit;

/**
 * 1.10 – czytelny wybór rodzaju faktury i danych nabywcy przed wystawieniem.
 *
 * Warstwa danych i walidacja pozostają w 0.83. Ten release porządkuje UX:
 * Faktura imienna / Faktura na firmę, dynamiczne etykiety pól i wyraźne
 * przypomnienie, że zapisany profil jest wspólnym źródłem dla PDF i KSeF.
 */
final class BCS_Release_110 {
    public static function init(): void {
        add_action('admin_footer', [__CLASS__, 'invoice_profile_ux'], 10020);
    }

    public static function invoice_profile_ux(): void {
        if (!is_admin() || !current_user_can('manage_options')) return;
        if (sanitize_key(wp_unslash($_GET['page'] ?? '')) !== 'bcs-registrations') return;
        if (!absint($_GET['view'] ?? 0)) return;
        ?>
        <style>
            .bcs-invoice-kind-110{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin:0 0 18px}
            .bcs-invoice-kind-110 button{appearance:none;text-align:left;border:1px solid #c3c4c7;background:#fff;border-radius:10px;padding:14px 15px;cursor:pointer;min-height:72px;transition:.15s ease}
            .bcs-invoice-kind-110 button:hover{border-color:#8c8f94;box-shadow:0 1px 3px rgba(0,0,0,.08)}
            .bcs-invoice-kind-110 button.is-active{border-color:#2271b1;box-shadow:0 0 0 1px #2271b1;background:#f0f6fc}
            .bcs-invoice-kind-110 strong{display:block;font-size:14px;margin-bottom:3px}
            .bcs-invoice-kind-110 span{display:block;color:#646970;font-size:12px;line-height:1.4}
            .bcs-invoice-profile-083 .bcs-invoice-type-native-110{position:absolute!important;width:1px!important;height:1px!important;overflow:hidden!important;clip:rect(1px,1px,1px,1px)!important;white-space:nowrap!important}
            .bcs-invoice-profile-note-110{margin:0 0 16px;padding:12px 14px;border-left:4px solid #2271b1;background:#f0f6fc;border-radius:4px}
            .bcs-invoice-profile-note-110 p{margin:0;color:#1d2327}
            .bcs-required-110{color:#d63638;font-weight:700}
            .bcs-invoice-profile-083 [data-bcs-nip-row-083][hidden]{display:none!important}
            @media(max-width:782px){.bcs-invoice-kind-110{grid-template-columns:1fr}}
        </style>
        <script>
        (()=>{
            const q=(root,sel)=>root?root.querySelector(sel):null;
            const setRequiredLabel=(label,text,required=true)=>{
                if(!label)return;
                const span=q(label,'span');
                if(!span)return;
                span.textContent=text;
                if(required){const mark=document.createElement('em');mark.className='bcs-required-110';mark.textContent=' *';span.appendChild(mark);}
            };
            const enhance=(panel)=>{
                if(!panel||panel.dataset.bcsInvoiceUx110==='1')return;
                const form=q(panel,'[data-bcs-invoice-form-083]');
                if(!form)return;
                panel.dataset.bcsInvoiceUx110='1';

                const intro=document.createElement('div');
                intro.className='bcs-invoice-profile-note-110';
                intro.innerHTML='<p><strong>Dane nabywcy przed wystawieniem faktury.</strong> Zapisane tutaj dane zostaną użyte identycznie w PDF faktury oraz w dokumencie wysyłanym do KSeF. Przed wygenerowaniem faktury sprawdź je i zapisz.</p>';
                form.insertAdjacentElement('afterbegin',intro);

                const select=form.elements.billing_type;
                if(!select)return;
                const nativeLabel=select.closest('label');
                if(nativeLabel)nativeLabel.classList.add('bcs-invoice-type-native-110');

                const switcher=document.createElement('div');
                switcher.className='bcs-invoice-kind-110';
                switcher.setAttribute('role','radiogroup');
                switcher.setAttribute('aria-label','Rodzaj faktury');
                switcher.innerHTML='\
<button type="button" data-invoice-kind-110="individual"><strong>Faktura imienna</strong><span>Dla osoby prywatnej. Nabywcą będzie wskazana osoba fizyczna.</span></button>\
<button type="button" data-invoice-kind-110="company"><strong>Faktura na firmę</strong><span>Dla firmy lub działalności. Wymagany jest poprawny 10-cyfrowy NIP.</span></button>';
                nativeLabel.insertAdjacentElement('beforebegin',switcher);

                const nameLabel=form.elements.billing_name?.closest('label');
                const streetLabel=form.elements.billing_street?.closest('label');
                const postalLabel=form.elements.billing_postal_code?.closest('label');
                const cityLabel=form.elements.billing_city?.closest('label');
                const nipLabel=form.elements.billing_nip?.closest('label');
                setRequiredLabel(streetLabel,'Ulica i numer');
                setRequiredLabel(postalLabel,'Kod pocztowy');
                setRequiredLabel(cityLabel,'Miejscowość');
                setRequiredLabel(nipLabel,'NIP');

                const sync=()=>{
                    const company=select.value==='company';
                    setRequiredLabel(nameLabel,company?'Nazwa firmy':'Imię i nazwisko');
                    switcher.querySelectorAll('[data-invoice-kind-110]').forEach(btn=>{
                        const active=btn.dataset.invoiceKind110===select.value;
                        btn.classList.toggle('is-active',active);
                        btn.setAttribute('aria-checked',active?'true':'false');
                    });
                    if(nipLabel)nipLabel.hidden=!company;
                    if(form.elements.billing_nip)form.elements.billing_nip.required=company;
                };
                switcher.addEventListener('click',e=>{
                    const btn=e.target.closest('[data-invoice-kind-110]');
                    if(!btn)return;
                    select.value=btn.dataset.invoiceKind110;
                    select.dispatchEvent(new Event('change',{bubbles:true}));
                    sync();
                });
                select.addEventListener('change',sync);
                sync();

                const view=panel.querySelector('.bcs-invoice-profile-view-083');
                if(view){
                    const first=view.querySelector('div strong');
                    if(first){
                        const txt=String(first.textContent||'').trim().toLowerCase();
                        if(txt==='firma')first.textContent='Faktura na firmę';
                        else if(txt==='osoba prywatna')first.textContent='Faktura imienna';
                    }
                    const firstLabel=view.querySelector('div span');
                    if(firstLabel)firstLabel.textContent='Rodzaj faktury';
                }
            };
            const scan=()=>document.querySelectorAll('[data-bcs-invoice-profile-083]').forEach(enhance);
            if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',scan,{once:true});else scan();
            window.setTimeout(scan,200);window.setTimeout(scan,700);
            new MutationObserver(scan).observe(document.body,{childList:true,subtree:true});
        })();
        </script>
        <?php
    }
}
