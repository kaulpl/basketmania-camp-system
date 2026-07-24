<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_042 {
    public static function init(): void {
        add_action('wp_ajax_bcs_042_get_camp_form', [__CLASS__, 'ajax_get_form']);
        add_action('wp_ajax_bcs_042_save_camp_form', [__CLASS__, 'ajax_save_form']);
        add_action('admin_footer', [__CLASS__, 'admin_footer']);
    }

    private static function fields(): array {
        return [
            'parent_first_name'=>['Imię opiekuna','text'],'parent_last_name'=>['Nazwisko opiekuna','text'],'parents_names'=>['Imiona i nazwiska rodziców','text'],'parent_email'=>['E-mail','email'],'parent_phone'=>['Telefon I','text'],'parent_phone_alt'=>['Telefon II','text'],'parent_postal_code'=>['Kod pocztowy','text'],'parent_city'=>['Miejscowość','text'],'parent_street'=>['Ulica','text'],'parent_house_number'=>['Nr domu / lokalu','text'],'child_first_name'=>['Imię uczestnika','text'],'child_last_name'=>['Nazwisko uczestnika','text'],'child_address'=>['Adres uczestnika (jeżeli inny)','textarea'],'child_birth_date'=>['Data urodzenia','date'],'child_pesel'=>['PESEL','text'],'child_height'=>['Wzrost (cm)','number'],'child_weight'=>['Waga (kg)','number'],'shirt_size'=>['Rozmiar stroju','text'],'child_club'=>['Klub','text'],'special_educational_needs'=>['Specjalne potrzeby edukacyjne','textarea'],'medical_notes'=>['Uwagi zdrowotne','textarea'],'dietary_notes'=>['Dieta i żywienie','textarea'],'vaccination_tetanus'=>['Szczepienie przeciw tężcowi – rok','text'],'vaccination_diphtheria'=>['Szczepienie przeciw błonicy – rok','text'],'vaccination_other'=>['Inne szczepienia','textarea'],'stay_contact'=>['Kontakt podczas pobytu','textarea'],'authorized_pickup'=>['Osoby upoważnione do odbioru','textarea'],'camp_notes'=>['Dodatkowe informacje dla organizatora','textarea'],'invoice_requested'=>['Faktura','checkbox'],'invoice_buyer_name'=>['Nabywca faktury','text'],'invoice_street'=>['Ulica do faktury','text'],'invoice_postal_code'=>['Kod pocztowy do faktury','text'],'invoice_city'=>['Miejscowość do faktury','text'],'invoice_nip'=>['NIP nabywcy','text'],'invoice_notes'=>['Dodatkowe dane na fakturze','textarea'],
        ];
    }

    private static function row(int $id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT r.*,a.status agreement_record_status, EXISTS(SELECT 1 FROM ".BCS_DB::table('agreement_versions')." av WHERE av.registration_id=r.id AND av.stage IN ('sent','signed')) has_final_agreement, EXISTS(SELECT 1 FROM ".BCS_DB::table('invoices')." i WHERE i.registration_id=r.id AND i.status IN ('generated','sent')) has_invoice FROM ".BCS_DB::table('registrations')." r LEFT JOIN ".BCS_DB::table('agreements')." a ON a.id=r.agreement_id WHERE r.id=%d LIMIT 1",$id));
    }

    private static function locked(object $r): bool {
        $agreement_locked=!empty($r->has_final_agreement)||in_array((string)($r->agreement_record_status??''),['pending','parent_signed','accepted'],true);
        $invoice_locked=!empty($r->has_invoice)||in_array((string)($r->invoice_status??''),['generated','sent'],true);
        return $agreement_locked||$invoice_locked;
    }

    public static function ajax_get_form(): void {
        if(!current_user_can('manage_options'))wp_send_json_error(['message'=>'Brak uprawnień.'],403);
        check_ajax_referer('bcs_042_camp_form','nonce');$id=absint($_POST['registration_id']??0);$r=self::row($id);
        if(!$r)wp_send_json_error(['message'=>'Nie znaleziono zgłoszenia.'],404);
        $values=[];foreach(self::fields() as $key=>$meta)$values[$key]=$key==='invoice_requested'?(int)!empty($r->{$key}):(string)($r->{$key}??'');
        wp_send_json_success(['values'=>$values,'locked'=>self::locked($r),'message'=>self::locked($r)?'Edycja jest zablokowana, ponieważ wygenerowano finalną umowę albo fakturę.':'']);
    }

    public static function ajax_save_form(): void {
        if(!current_user_can('manage_options'))wp_send_json_error(['message'=>'Brak uprawnień.'],403);
        check_ajax_referer('bcs_042_camp_form','nonce');$id=absint($_POST['registration_id']??0);$r=self::row($id);
        if(!$r)wp_send_json_error(['message'=>'Nie znaleziono zgłoszenia.'],404);
        if(self::locked($r))wp_send_json_error(['message'=>'Nie można aktualizować danych formularza po wygenerowaniu finalnej umowy albo faktury.'],409);
        $data=[];foreach(self::fields() as $key=>$meta){$type=$meta[1];if($type==='checkbox')$data[$key]=!empty($_POST[$key])?1:0;elseif($type==='email')$data[$key]=sanitize_email(wp_unslash($_POST[$key]??''));elseif($type==='number')$data[$key]=max(0,(float)str_replace(',','.',(string)wp_unslash($_POST[$key]??0)));elseif($type==='textarea')$data[$key]=sanitize_textarea_field(wp_unslash($_POST[$key]??''));else$data[$key]=sanitize_text_field(wp_unslash($_POST[$key]??''));}
        foreach(['parent_first_name','parent_last_name','parent_email','parent_phone','child_first_name','child_last_name'] as $required){if(trim((string)$data[$required])==='')wp_send_json_error(['message'=>'Uzupełnij wymagane pole: '.self::fields()[$required][0].'.'],422);}
        $data['updated_at']=BCS_Utils::now();global $wpdb;$ok=$wpdb->update(BCS_DB::table('registrations'),$data,['id'=>$id]);
        if($ok===false)wp_send_json_error(['message'=>'Nie udało się zapisać danych.'],500);
        BCS_Utils::log('camp_form_edited_by_admin_ajax',['fields'=>array_keys($data)],$id,(int)$r->agreement_id);
        wp_send_json_success(['message'=>'Dane formularza obozowego zostały zapisane.']);
    }

    public static function admin_footer(): void {
        if(!current_user_can('manage_options'))return;$page=sanitize_key($_GET['page']??'');$id=absint($_GET['view']??0);if($page!=='bcs-registrations'||!$id)return;$nonce=wp_create_nonce('bcs_042_camp_form');?>
        <style>.bcs-042-actions{display:flex;gap:8px;justify-content:flex-end;margin:14px 0 0}.bcs-042-editor-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.bcs-042-field{display:flex;flex-direction:column;gap:5px}.bcs-042-field textarea{min-height:90px}.bcs-042-wide{grid-column:1/-1}.bcs-042-lock{padding:10px 12px;background:#fff7ed;border-left:4px solid #f97316;margin:12px 0}@media(max-width:782px){.bcs-042-editor-grid{grid-template-columns:1fr}}</style>
        <script>(()=>{const id=<?php echo (int)$id;?>,nonce=<?php echo wp_json_encode($nonce);?>;const panel=[...document.querySelectorAll('.bcs-accordion-panel')].find(p=>p.textContent.includes('Dane z formularza zgłoszeniowego')||p.textContent.includes('Dane z formularza obozowego'));if(!panel)return;const title=panel.querySelector('summary strong');if(title)title.textContent='Dane z formularza obozowego';const content=panel.querySelector('.bcs-accordion-content');if(!content)return;const original=content.innerHTML;const edit=document.createElement('button');edit.type='button';edit.className='button bcs-042-edit';edit.textContent='Edytuj';content.insertAdjacentElement('afterbegin',edit);async function call(action,body={}){const fd=new FormData();fd.append('action',action);fd.append('nonce',nonce);fd.append('registration_id',id);Object.entries(body).forEach(([k,v])=>fd.append(k,v));const r=await fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:fd});return r.json()}function restore(){content.innerHTML=original;content.insertAdjacentElement('afterbegin',edit);edit.onclick=start}async function start(){edit.disabled=true;const j=await call('bcs_042_get_camp_form');edit.disabled=false;if(!j.success){alert(j.data?.message||'Nie udało się pobrać danych.');return}if(j.data.locked){const n=document.createElement('div');n.className='bcs-042-lock';n.textContent=j.data.message;content.prepend(n);edit.disabled=true;return}const fields=<?php echo wp_json_encode(self::fields(),JSON_UNESCAPED_UNICODE);?>,grid=document.createElement('div');grid.className='bcs-042-editor-grid';Object.entries(fields).forEach(([name,meta])=>{const[label,type]=meta,wrap=document.createElement('label');wrap.className='bcs-042-field'+(type==='textarea'?' bcs-042-wide':'');const s=document.createElement('span');s.textContent=label;let el;if(type==='textarea')el=document.createElement('textarea');else{el=document.createElement('input');el.type=type==='checkbox'?'checkbox':type;if(type==='number')el.step='0.01'}el.name=name;if(type==='checkbox')el.checked=!!j.data.values[name];else el.value=j.data.values[name]??'';wrap.append(s,el);grid.append(wrap)});const actions=document.createElement('div');actions.className='bcs-042-actions';const cancel=document.createElement('button');cancel.type='button';cancel.className='button';cancel.textContent='Anuluj';const save=document.createElement('button');save.type='button';save.className='button button-primary';save.textContent='Zapisz';actions.append(cancel,save);content.innerHTML='';content.append(grid,actions);cancel.onclick=restore;save.onclick=async()=>{save.disabled=true;const body={};grid.querySelectorAll('[name]').forEach(el=>body[el.name]=el.type==='checkbox'?(el.checked?'1':''):el.value);const res=await call('bcs_042_save_camp_form',body);save.disabled=false;if(!res.success){alert(res.data?.message||'Nie udało się zapisać danych.');return}restore();location.reload()}}edit.onclick=start})();</script><?php
    }
}
