<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_0190 {
    public static function init(): void {
        add_action('wp_ajax_bcs_feedback_status_0190', [self::class, 'ajax_feedback_status']);
        add_action('wp_ajax_bcs_registration_price_0190', [self::class, 'ajax_registration_price']);
        add_action('wp_ajax_bcs_withdraw_agreement_0190', [self::class, 'ajax_withdraw_agreement']);
        add_action('admin_post_nopriv_bcs_agreement_view', [self::class, 'log_agreement_open'], 0);
        add_action('admin_post_bcs_agreement_view', [self::class, 'log_agreement_open'], 0);
        add_action('admin_head', [self::class, 'admin_styles'], 300);
        add_action('admin_footer', [self::class, 'admin_scripts'], 300);
        add_action('wp_head', [self::class, 'portal_styles'], 300);
        add_action('wp_footer', [self::class, 'portal_scripts'], 300);
    }

    private static function json_guard(string $action): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Brak uprawnień.'],403);
        check_ajax_referer($action, 'nonce');
    }

    public static function ajax_feedback_status(): void {
        self::json_guard('bcs_feedback_status_0190');
        $id=absint($_POST['feedback_id']??0);$status=sanitize_key($_POST['status']??'');
        if(!$id||!in_array($status,['new','in_progress','resolved','cancelled'],true)) wp_send_json_error(['message'=>'Nieprawidłowe dane.'],422);
        global $wpdb;$data=['status'=>$status,'updated_at'=>BCS_Utils::now(),'resolved_by'=>null,'resolved_at'=>null];
        if($status==='resolved'){$data['resolved_by']=get_current_user_id();$data['resolved_at']=BCS_Utils::now();}
        $ok=$wpdb->update(BCS_DB::table('feedback'),$data,['id'=>$id]);
        if($ok===false) wp_send_json_error(['message'=>'Nie udało się zmienić statusu.'],500);
        wp_send_json_success(['message'=>'Status zgłoszenia został zaktualizowany.','status'=>$status]);
    }

    public static function ajax_registration_price(): void {
        self::json_guard('bcs_registration_price_0190');
        $id=absint($_POST['registration_id']??0);$amount=(float)str_replace(',','.',(string)($_POST['amount']??''));
        if(!$id||$amount<=0) wp_send_json_error(['message'=>'Podaj prawidłową cenę.'],422);
        global $wpdb;$row=$wpdb->get_row($wpdb->prepare("SELECT r.*,a.status agreement_real_status FROM ".BCS_DB::table('registrations')." r LEFT JOIN ".BCS_DB::table('agreements')." a ON a.id=r.agreement_id WHERE r.id=%d",$id));
        if(!$row) wp_send_json_error(['message'=>'Nie znaleziono zgłoszenia.'],404);
        if(in_array((string)$row->agreement_real_status,['pending','accepted'],true)||in_array((string)$row->agreement_status,['pending','accepted'],true)) wp_send_json_error(['message'=>'Cena jest zablokowana po wysłaniu umowy do podpisu.'],409);
        $ok=$wpdb->update(BCS_DB::table('registrations'),['total_amount'=>$amount,'updated_at'=>BCS_Utils::now()],['id'=>$id]);
        if($ok===false) wp_send_json_error(['message'=>'Nie udało się zapisać ceny.'],500);
        if(!empty($row->agreement_id)&&$row->agreement_real_status==='draft'&&class_exists('BCS_Agreements')) BCS_Agreements::build_for_registration($id,'draft',false);
        BCS_Utils::log('registration_price_changed',['amount'=>$amount,'actor'=>'administrator'],$id,(int)($row->agreement_id??0));
        wp_send_json_success(['message'=>'Cena została zaktualizowana.','amount'=>number_format($amount,2,',',' ').' zł']);
    }

    public static function ajax_withdraw_agreement(): void {
        self::json_guard('bcs_withdraw_agreement_0190');
        $id=absint($_POST['registration_id']??0);global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare("SELECT r.*,a.status agreement_real_status FROM ".BCS_DB::table('registrations')." r LEFT JOIN ".BCS_DB::table('agreements')." a ON a.id=r.agreement_id WHERE r.id=%d",$id));
        if(!$row) wp_send_json_error(['message'=>'Nie znaleziono zgłoszenia.'],404);
        if($row->agreement_real_status!=='pending'||$row->agreement_status==='accepted') wp_send_json_error(['message'=>'Można wycofać wyłącznie niepodpisaną umowę.'],409);
        $wpdb->update(BCS_DB::table('agreements'),['status'=>'draft','version'=>'draft'],['id'=>(int)$row->agreement_id]);
        $wpdb->update(BCS_DB::table('registrations'),['agreement_status'=>'draft','status'=>'form_verified','updated_at'=>BCS_Utils::now()],['id'=>$id]);
        BCS_Utils::log('agreement_withdrawn_before_signature',['actor'=>'administrator'],$id,(int)$row->agreement_id);
        wp_send_json_success(['message'=>'Umowa została wycofana. Cena jest ponownie edytowalna.']);
    }

    public static function log_agreement_open(): void {
        $id=absint($_GET['agreement']??0);$token=sanitize_text_field(wp_unslash($_GET['token']??''));if(!$id)return;
        global $wpdb;$row=$wpdb->get_row($wpdb->prepare("SELECT a.id,a.status,a.registration_id,r.public_token FROM ".BCS_DB::table('agreements')." a JOIN ".BCS_DB::table('registrations')." r ON r.id=a.registration_id WHERE a.id=%d",$id));
        if(!$row)return;if(!current_user_can('manage_options')&&!hash_equals((string)$row->public_token,$token))return;
        $event=$row->status==='draft'?'agreement_template_opened':'agreement_opened_for_signature';
        $already=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".BCS_DB::table('logs')." WHERE registration_id=%d AND agreement_id=%d AND event_type=%s",(int)$row->registration_id,$id,$event));
        if(!$already) BCS_Utils::log($event,['opened_at'=>BCS_Utils::now(),'ip'=>BCS_Utils::client_ip(),'actor'=>current_user_can('manage_options')?'administrator':'parent'],(int)$row->registration_id,$id);
    }

    private static function plugin_page(): bool { $p=sanitize_key(wp_unslash($_GET['page']??''));return is_admin()&&str_starts_with($p,'bcs-'); }

    public static function admin_styles(): void { if(!self::plugin_page())return; ?>
<style>
.bcs-toast-0190{position:fixed;left:50%;bottom:28px;transform:translateX(-50%) translateY(20px);z-index:100000;background:#111827;color:#fff;padding:11px 16px;border-radius:10px;box-shadow:0 12px 35px rgba(0,0,0,.25);opacity:0;transition:.2s;max-width:420px;text-align:center}.bcs-toast-0190.show{opacity:1;transform:translateX(-50%) translateY(0)}.bcs-toast-0190.error{background:#991b1b}.bcs-result-popup-0190{position:fixed;inset:0;z-index:100001;display:grid;place-items:center;background:rgba(15,23,42,.28);opacity:0;pointer-events:none;transition:.18s}.bcs-result-popup-0190.show{opacity:1;pointer-events:auto}.bcs-result-popup-0190__box{min-width:280px;max-width:420px;background:#fff;border-radius:18px;padding:28px;text-align:center;box-shadow:0 24px 70px rgba(0,0,0,.3)}.bcs-result-popup-0190__icon{width:82px;height:82px;border-radius:50%;display:grid;place-items:center;margin:0 auto 16px;font-size:52px;font-weight:800}.bcs-result-popup-0190.success .bcs-result-popup-0190__icon{background:#dcfce7;color:#16a34a}.bcs-result-popup-0190.error .bcs-result-popup-0190__icon{background:#fee2e2;color:#dc2626}.bcs-result-popup-0190 h3{margin:0;font-size:20px}.bcs-handling-section-0190{margin-top:20px;border:1px solid #fed7aa;border-radius:12px;background:#fff7ed;padding:18px}.bcs-handling-section-0190>h2{margin:0 0 14px;color:#9a3412}.bcs-price-edit-0190{margin-left:8px}.bcs-settings-docs-icon-0190:before{color:#f97316!important}
</style><?php }

    public static function admin_scripts(): void { if(!self::plugin_page())return;$page=sanitize_key(wp_unslash($_GET['page']??''));$nonceFeedback=wp_create_nonce('bcs_feedback_status_0190');$noncePrice=wp_create_nonce('bcs_registration_price_0190');$nonceWithdraw=wp_create_nonce('bcs_withdraw_agreement_0190'); ?>
<div class="bcs-toast-0190" id="bcs-toast-0190"></div><div class="bcs-result-popup-0190" id="bcs-result-popup-0190"><div class="bcs-result-popup-0190__box"><div class="bcs-result-popup-0190__icon">✓</div><h3></h3></div></div>
<script>
(function(){const page=<?php echo wp_json_encode($page);?>,ajax=<?php echo wp_json_encode(admin_url('admin-ajax.php'));?>;
function toast(msg,ok=true){const t=document.getElementById('bcs-toast-0190');t.textContent=msg;t.className='bcs-toast-0190 '+(ok?'':'error')+' show';setTimeout(()=>t.classList.remove('show'),2200)}
function popup(msg,ok=true){const p=document.getElementById('bcs-result-popup-0190');p.className='bcs-result-popup-0190 '+(ok?'success':'error')+' show';p.querySelector('.bcs-result-popup-0190__icon').textContent=ok?'✓':'×';p.querySelector('h3').textContent=msg;setTimeout(()=>p.classList.remove('show'),2000)}
window.bcsPopup0190=popup;
async function post(data){const r=await fetch(ajax,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:new URLSearchParams(data)});let j={};try{j=await r.json()}catch(e){}if(!r.ok||!j.success)throw new Error(j?.data?.message||'Nie udało się wykonać działania.');return j.data||{};}
function replaceDraftWords(root=document.body){const w=document.createTreeWalker(root,NodeFilter.SHOW_TEXT);let n;while(n=w.nextNode()){if(n.parentElement&&['SCRIPT','STYLE','TEXTAREA'].includes(n.parentElement.tagName))continue;n.nodeValue=n.nodeValue.replace(/draft umowy/gi,'wzór umowy').replace(/draft/gi,'wzór');}}
function feedbackAjax(){if(page!=='bcs-feedback')return;document.querySelectorAll('.bcs-feedback-row-actions form').forEach(f=>{if(f.dataset.ajax0190)return;f.dataset.ajax0190='1';f.addEventListener('submit',async e=>{e.preventDefault();const b=f.querySelector('button');b.disabled=true;try{const d=await post({action:'bcs_feedback_status_0190',nonce:<?php echo wp_json_encode($nonceFeedback);?>,feedback_id:f.querySelector('[name=feedback_id]').value,status:f.querySelector('[name=status]').value});const row=f.closest('tr');const badge=row.querySelector('.bcs-feedback-status');if(badge){badge.textContent=b.textContent;badge.className='bcs-feedback-status status-'+f.querySelector('[name=status]').value}toast(d.message,true);f.remove();}catch(err){toast(err.message,false);b.disabled=false}})})}
function settingsLayout(){if(page!=='bcs-settings')return;const wrap=document.querySelector('.wrap.bcs-admin');if(!wrap)return;document.querySelectorAll('summary,h2,h3,strong').forEach(el=>{const t=el.textContent.trim();if(t==='Ustawienia wtyczki')el.textContent='Ustawienia ogólne';if(t==='Powiadomienia workflow')el.textContent='Ustawienia powiadomień';if(t==='Ustawienia dokumentów i automatyzacji'){const i=el.querySelector('.dashicons')||el.previousElementSibling;if(i)i.classList.add('bcs-settings-docs-icon-0190')}});const details=[...wrap.querySelectorAll('details,.bcs-settings-section-0187')];const fake=details.find(d=>/Ustawienia ogólne/i.test(d.textContent)&&!/Ustawienia wtyczki/i.test(d.textContent));if(fake&&fake===details[0])fake.remove();const email=details.find(d=>/^\s*E-?mail/i.test(d.textContent));const notify=details.find(d=>/Ustawienia powiadomień|Powiadomienia workflow/i.test(d.textContent));if(email&&notify&&email.parentNode===notify.parentNode)email.after(notify);}
function registrationCard(){if(page!=='bcs-registrations'||!new URLSearchParams(location.search).get('view'))return;replaceDraftWords();const detail=document.querySelector('.bcs-registration-detail,.bcs-registration-card,.bcs-crm-detail,.wrap.bcs-admin');if(!detail)return;const text=detail.textContent.toLowerCase();const cancelled=/zgłoszenie anulowane|status:\s*anulowane|anulowano/.test(text);let section=detail.querySelector('.bcs-handling-section-0190');if(!section){section=document.createElement('section');section.className='bcs-handling-section-0190';section.innerHTML='<h2>Obsługa zgłoszenia</h2><div class="bcs-handling-body-0190"></div>';detail.appendChild(section)}const body=section.querySelector('.bcs-handling-body-0190');document.querySelectorAll('.bcs-quick-actions,.bcs-registration-actions,.bcs-crm-actions').forEach(x=>{if(x!==section&&!section.contains(x))body.appendChild(x)});document.querySelectorAll('button,a').forEach(el=>{const t=el.textContent.toLowerCase();if(/zaakceptuj formularz|akceptacja formularza|dodaj notatkę|dodaj zadanie|telefon/.test(t)&&!section.contains(el)){const holder=el.closest('form,.bcs-card,.bcs-panel,div');if(holder)body.appendChild(holder)}if(cancelled&&/wyślij|wygeneruj|umow|faktur|płatno|sms|formularz/.test(t)){el.disabled=true;el.setAttribute('aria-disabled','true');el.title='Zgłoszenie anulowane — akcja niedostępna.'}});
const id=(document.querySelector('[name=registration_id]')||document.querySelector('[name=id]'))?.value||new URLSearchParams(location.search).get('registration_id')||new URLSearchParams(location.search).get('id');if(!id)return;document.querySelectorAll('*').forEach(el=>{if(el.children.length>6)return;if(/^cena$/i.test(el.textContent.trim())){const tile=el.parentElement;if(tile&&!tile.querySelector('.bcs-price-edit-0190')){const b=document.createElement('button');b.type='button';b.className='button button-small bcs-price-edit-0190';b.textContent='Edytuj cenę';b.onclick=async()=>{const v=prompt('Podaj nową cenę za turnus:');if(!v)return;try{const d=await post({action:'bcs_registration_price_0190',nonce:<?php echo wp_json_encode($noncePrice);?>,registration_id:id,amount:v});popup(d.message,true);const strong=tile.querySelector('strong,.value');if(strong)strong.textContent=d.amount}catch(err){popup(err.message,false)}};tile.appendChild(b)}}});
if(!cancelled&&/umowa.*(wysłana|gotowa).*podpis/i.test(text)&&!/umowa.*podpisana/i.test(text)&&!body.querySelector('.bcs-withdraw-agreement-0190')){const b=document.createElement('button');b.type='button';b.className='button bcs-withdraw-agreement-0190';b.textContent='Wycofaj umowę przed podpisem';b.onclick=async()=>{try{const d=await post({action:'bcs_withdraw_agreement_0190',nonce:<?php echo wp_json_encode($nonceWithdraw);?>,registration_id:id});popup(d.message,true);b.remove()}catch(err){popup(err.message,false)}};body.appendChild(b)}
body.querySelectorAll('form').forEach(f=>{if(f.dataset.ajax0190||f.querySelector('input[type=file]'))return;f.dataset.ajax0190='1';f.addEventListener('submit',async e=>{const submit=e.submitter;if(!submit)return;e.preventDefault();submit.disabled=true;try{const r=await fetch(f.action||location.href,{method:(f.method||'POST').toUpperCase(),credentials:'same-origin',body:new FormData(f)});if(!r.ok)throw new Error('Nie udało się wykonać działania.');popup('Działanie wykonane poprawnie.',true);submit.disabled=false}catch(err){popup(err.message,false);submit.disabled=false}})})}
function dedupeLocked(){document.querySelectorAll('.bcs-alert,.notice,.bcs-card,.bcs-lock-message').forEach((el,i,all)=>{const t=el.textContent.trim().toLowerCase();if(!/formularz.*zablokowan|zablokowany formularz/.test(t))return;const first=all.find(x=>x!==el&&/formularz.*zablokowan|zablokowany formularz/.test(x.textContent.trim().toLowerCase()));if(first)el.remove()})}
function run(){replaceDraftWords();feedbackAjax();settingsLayout();registrationCard();dedupeLocked()}document.addEventListener('DOMContentLoaded',run);new MutationObserver(()=>{feedbackAjax();dedupeLocked()}).observe(document.documentElement,{childList:true,subtree:true});})();
</script><?php }

    private static function portal_request(): bool { if(is_admin()||wp_doing_ajax())return false;$p=get_queried_object();return $p instanceof WP_Post&&has_shortcode((string)$p->post_content,'basketmania_portal'); }
    public static function portal_styles(): void { if(!self::portal_request())return; ?><style>.bcs-agreement-process-0190{margin:15px 0;padding:16px;border-radius:10px;background:#dcfce7;color:#14532d;line-height:1.55}.bcs-agreement-process-0190 strong{display:block;font-size:18px;margin-bottom:6px}.bcs-sign-locked-0190{opacity:.55;pointer-events:none}</style><?php }
    public static function portal_scripts(): void { if(!self::portal_request())return; ?>
<script>(function(){function txt(root=document.body){const w=document.createTreeWalker(root,NodeFilter.SHOW_TEXT);let n;while(n=w.nextNode()){if(n.parentElement&&['SCRIPT','STYLE'].includes(n.parentElement.tagName))continue;n.nodeValue=n.nodeValue.replace(/Organizator zaakceptował formularz i przygotowuje lub wysłał draft umowy\./g,'Organizator zaakceptował formularz i przekazał wzór umowy.').replace(/draft umowy/gi,'wzór umowy').replace(/draft/gi,'wzór')}}function init(){txt();const links=[...document.querySelectorAll('a,button')].filter(x=>/otwórz umowę/i.test(x.textContent));const open=links[0];if(!open)return;const wrap=open.closest('.bcs-card,.bcs-panel,section,div');if(!wrap)return;if(!wrap.querySelector('.bcs-agreement-process-0190')){const box=document.createElement('div');box.className='bcs-agreement-process-0190';box.innerHTML='<strong>Umowa jest gotowa do podpisu.</strong>Należy otworzyć umowę, zapoznać się z jej treścią oraz wszystkimi załącznikami, a następnie zamknąć podgląd. Po powrocie do Panelu Rodzica zaznacz wszystkie wymagane oświadczenia. Dopiero wtedy przycisk „Podpisz umowę kodem SMS” stanie się aktywny.';wrap.insertBefore(box,open)}const checks=[...wrap.querySelectorAll('input[type=checkbox]')];const sign=[...wrap.querySelectorAll('button')].find(x=>/podpisz.*sms|wyślij kod|potwierdź podpis/i.test(x.textContent));let opened=sessionStorage.getItem('bcs_agreement_opened')==='1';function sync(){const all=checks.length>0&&checks.every(c=>c.checked);if(sign){sign.disabled=!(opened&&all);sign.classList.toggle('bcs-sign-locked-0190',sign.disabled)}}open.addEventListener('click',()=>{opened=true;sessionStorage.setItem('bcs_agreement_opened','1');setTimeout(sync,300)});checks.forEach(c=>c.addEventListener('change',sync));sync()}document.addEventListener('DOMContentLoaded',init);new MutationObserver(init).observe(document.documentElement,{childList:true,subtree:true});})();</script><?php }
}
