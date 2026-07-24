<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_043 {
    public static function init(): void {
        add_action('admin_footer', [__CLASS__, 'admin_footer'], 100);
    }

    private static function fields(): array {
        return [
            'parent_first_name'=>['Imię opiekuna','text'],'parent_last_name'=>['Nazwisko opiekuna','text'],'parents_names'=>['Imiona i nazwiska rodziców','text'],'parent_email'=>['E-mail','email'],'parent_phone'=>['Telefon I','text'],'parent_phone_alt'=>['Telefon II','text'],'parent_postal_code'=>['Kod pocztowy','text'],'parent_city'=>['Miejscowość','text'],'parent_street'=>['Ulica','text'],'parent_house_number'=>['Nr domu / lokalu','text'],'child_first_name'=>['Imię uczestnika','text'],'child_last_name'=>['Nazwisko uczestnika','text'],'child_address'=>['Adres uczestnika (jeżeli inny)','textarea'],'child_birth_date'=>['Data urodzenia','date'],'child_pesel'=>['PESEL','text'],'child_height'=>['Wzrost (cm)','number'],'child_weight'=>['Waga (kg)','number'],'shirt_size'=>['Rozmiar stroju','text'],'child_club'=>['Klub','text'],'special_educational_needs'=>['Specjalne potrzeby edukacyjne','textarea'],'medical_notes'=>['Uwagi zdrowotne','textarea'],'dietary_notes'=>['Dieta i żywienie','textarea'],'vaccination_tetanus'=>['Szczepienie przeciw tężcowi – rok','text'],'vaccination_diphtheria'=>['Szczepienie przeciw błonicy – rok','text'],'vaccination_other'=>['Inne szczepienia','textarea'],'stay_contact'=>['Kontakt podczas pobytu','textarea'],'authorized_pickup'=>['Osoby upoważnione do odbioru','textarea'],'camp_notes'=>['Dodatkowe informacje dla organizatora','textarea'],'invoice_requested'=>['Faktura','checkbox'],'invoice_buyer_name'=>['Nabywca faktury','text'],'invoice_street'=>['Ulica do faktury','text'],'invoice_postal_code'=>['Kod pocztowy do faktury','text'],'invoice_city'=>['Miejscowość do faktury','text'],'invoice_nip'=>['NIP nabywcy','text'],'invoice_notes'=>['Dodatkowe dane na fakturze','textarea'],
        ];
    }

    public static function admin_footer(): void {
        if (!current_user_can('manage_options')) return;
        $page=sanitize_key($_GET['page']??'');
        $id=absint($_GET['view']??0);
        if($page!=='bcs-registrations'||!$id)return;
        $nonce=wp_create_nonce('bcs_042_camp_form');
        ?>
        <script>
        (()=>{
            const id=<?php echo (int)$id;?>;
            const nonce=<?php echo wp_json_encode($nonce);?>;
            const fields=<?php echo wp_json_encode(self::fields(), JSON_UNESCAPED_UNICODE);?>;
            const panel=[...document.querySelectorAll('.bcs-accordion-panel')].find(p=>p.textContent.includes('Dane z formularza obozowego')||p.textContent.includes('Dane z formularza zgłoszeniowego'));
            if(!panel)return;
            const title=panel.querySelector('summary strong');
            if(title)title.textContent='Dane z formularza obozowego';
            const content=panel.querySelector('.bcs-accordion-content');
            if(!content)return;

            async function call(action,body={}){
                const fd=new FormData();
                fd.append('action',action);fd.append('nonce',nonce);fd.append('registration_id',id);
                Object.entries(body).forEach(([k,v])=>fd.append(k,v));
                const r=await fetch(ajaxurl,{method:'POST',credentials:'same-origin',cache:'no-store',body:fd});
                return r.json();
            }

            function displayValue(name,value){
                if(fields[name][1]==='checkbox')return value?'tak':'nie';
                const v=String(value??'').trim();
                return v===''?'—':v;
            }

            function renderReadonly(values,locked=false,message=''){
                content.innerHTML='';
                const toolbar=document.createElement('div');
                toolbar.className='bcs-042-actions';
                const edit=document.createElement('button');
                edit.type='button';edit.className='button bcs-043-edit';edit.textContent='Edytuj';
                if(locked){edit.disabled=true;edit.title=message||'Edycja jest zablokowana.';}
                toolbar.append(edit);
                if(locked&&message){const note=document.createElement('div');note.className='bcs-042-lock';note.textContent=message;content.append(note);}
                const grid=document.createElement('div');grid.className='bcs-detail-grid bcs-form-preview-grid';
                Object.entries(fields).forEach(([name,meta])=>{
                    const item=document.createElement('div');
                    item.className='bcs-detail-item'+(meta[1]==='textarea'?' bcs-detail-wide':'');
                    const label=document.createElement('span');label.textContent=meta[0];
                    const value=document.createElement('strong');value.style.whiteSpace='pre-line';value.textContent=displayValue(name,values[name]);
                    item.append(label,value);grid.append(item);
                });
                content.append(grid,toolbar);
                edit.onclick=()=>renderEditor(values);
            }

            function renderEditor(values){
                content.innerHTML='';
                const grid=document.createElement('div');grid.className='bcs-042-editor-grid';
                Object.entries(fields).forEach(([name,meta])=>{
                    const [label,type]=meta,wrap=document.createElement('label');
                    wrap.className='bcs-042-field'+(type==='textarea'?' bcs-042-wide':'');
                    const s=document.createElement('span');s.textContent=label;
                    let el;
                    if(type==='textarea')el=document.createElement('textarea');
                    else{el=document.createElement('input');el.type=type==='checkbox'?'checkbox':type;if(type==='number')el.step='0.01';}
                    el.name=name;
                    if(type==='checkbox')el.checked=!!values[name];else el.value=values[name]??'';
                    wrap.append(s,el);grid.append(wrap);
                });
                const actions=document.createElement('div');actions.className='bcs-042-actions';
                const cancel=document.createElement('button');cancel.type='button';cancel.className='button';cancel.textContent='Anuluj';
                const save=document.createElement('button');save.type='button';save.className='button button-primary';save.textContent='Zapisz';
                actions.append(cancel,save);content.append(grid,actions);
                cancel.onclick=()=>renderReadonly(values);
                save.onclick=async()=>{
                    save.disabled=true;
                    const body={};grid.querySelectorAll('[name]').forEach(el=>body[el.name]=el.type==='checkbox'?(el.checked?'1':''):el.value);
                    const res=await call('bcs_042_save_camp_form',body);
                    if(!res.success){save.disabled=false;alert(res.data?.message||'Nie udało się zapisać danych.');return;}
                    const fresh=await call('bcs_042_get_camp_form');
                    if(!fresh.success){save.disabled=false;alert(fresh.data?.message||'Dane zapisano, ale nie udało się odświeżyć widoku.');return;}
                    renderReadonly(fresh.data.values,!!fresh.data.locked,fresh.data.message||'');
                };
            }

            async function initialize(){
                const old=content.querySelector('.bcs-042-edit');if(old)old.remove();
                const fresh=await call('bcs_042_get_camp_form');
                if(!fresh.success)return;
                renderReadonly(fresh.data.values,!!fresh.data.locked,fresh.data.message||'');
            }
            initialize();
        })();
        </script>
        <?php
    }
}
