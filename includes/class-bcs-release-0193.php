<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_0193 {
    public static function init(): void {
        add_action('wp_ajax_bcs_update_registration_price_0193', [self::class, 'ajax_update_price']);
        add_action('wp_head', [self::class, 'front_styles'], 500);
        add_action('wp_footer', [self::class, 'front_scripts'], 500);
        add_action('admin_head', [self::class, 'admin_styles'], 500);
        add_action('admin_footer', [self::class, 'admin_scripts'], 500);
    }

    public static function ajax_update_price(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Brak uprawnień.'], 403);
        check_ajax_referer('bcs_update_registration_price_0193', 'nonce');

        $id = absint($_POST['registration_id'] ?? 0);
        $raw = str_replace([' ', ','], ['', '.'], (string)($_POST['amount'] ?? ''));
        $amount = round((float)$raw, 2);
        if (!$id || $amount <= 0) wp_send_json_error(['message' => 'Podaj prawidłową cenę.'], 422);

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT r.*, a.status agreement_real_status FROM " . BCS_DB::table('registrations') . " r LEFT JOIN " . BCS_DB::table('agreements') . " a ON a.id=r.agreement_id WHERE r.id=%d",
            $id
        ));
        if (!$row) wp_send_json_error(['message' => 'Nie znaleziono zgłoszenia.'], 404);
        if ($row->status === 'cancelled') wp_send_json_error(['message' => 'Nie można edytować ceny anulowanego zgłoszenia.'], 409);

        $locked = in_array((string)$row->agreement_status, ['pending', 'accepted'], true)
            || in_array((string)$row->agreement_real_status, ['pending', 'accepted'], true)
            || !empty($row->agreement_sent_at);
        if ($locked) wp_send_json_error(['message' => 'Cena jest zablokowana po wysłaniu umowy do podpisu.'], 409);

        $ok = $wpdb->update(BCS_DB::table('registrations'), [
            'total_amount' => $amount,
            'updated_at' => BCS_Utils::now(),
        ], ['id' => $id]);
        if ($ok === false) wp_send_json_error(['message' => 'Nie udało się zapisać ceny.'], 500);

        if (!empty($row->agreement_id) && (string)$row->agreement_real_status === 'draft' && class_exists('BCS_Agreements')) {
            BCS_Agreements::build_for_registration($id, 'draft', false);
        }

        BCS_Utils::log('registration_price_changed', [
            'previous_amount' => (float)$row->total_amount,
            'amount' => $amount,
            'actor' => 'administrator',
        ], $id, (int)($row->agreement_id ?? 0));

        wp_send_json_success([
            'message' => 'Cena została zaktualizowana.',
            'amount' => number_format($amount, 2, ',', ' ') . ' zł',
        ]);
    }

    public static function front_styles(): void {
        if (is_admin()) return;
        ?>
        <style>
        .bcs-check{position:relative;display:flex!important;align-items:center!important;gap:12px!important;cursor:pointer}
        .bcs-check input[type="checkbox"]{appearance:none!important;-webkit-appearance:none!important;width:48px!important;height:26px!important;min-width:48px!important;margin:0!important;border:0!important;border-radius:999px!important;background:#cbd5e1!important;box-shadow:inset 0 0 0 1px rgba(15,23,42,.08)!important;position:relative!important;cursor:pointer!important;transition:.2s ease!important}
        .bcs-check input[type="checkbox"]:before{content:""!important;position:absolute!important;left:3px!important;top:3px!important;width:20px!important;height:20px!important;border-radius:50%!important;background:#fff!important;box-shadow:0 2px 5px rgba(15,23,42,.25)!important;transition:.2s ease!important}
        .bcs-check input[type="checkbox"]:checked{background:#16a34a!important}
        .bcs-check input[type="checkbox"]:checked:before{transform:translateX(22px)!important}
        .bcs-check input[type="checkbox"]:focus-visible{outline:3px solid rgba(22,163,74,.25)!important;outline-offset:2px!important}
        .bcs-flash-autohide-0193{transition:opacity .3s ease,transform .3s ease}
        .bcs-flash-autohide-0193.is-hiding{opacity:0;transform:translateY(-6px)}
        </style>
        <?php
    }

    public static function front_scripts(): void {
        if (is_admin()) return;
        ?>
        <script>
        (function(){
            const phrase='Dane zostały zapisane i ponownie przekazane organizatorowi.';
            const nodes=[...document.querySelectorAll('.bcs-success,.bcs-alert,.bcs-card,.bcs-status-card,p,div')];
            const box=nodes.find(el=>el.children.length<8 && (el.textContent||'').trim()===phrase);
            if(!box)return;
            box.classList.add('bcs-flash-autohide-0193');
            setTimeout(()=>{
                box.classList.add('is-hiding');
                setTimeout(()=>box.remove(),320);
            },3000);
        })();
        </script>
        <?php
    }

    public static function admin_styles(): void {
        if (!is_admin()) return;
        ?>
        <style>
        .bcs-price-edit-wrap-0193{display:inline-flex;align-items:center;gap:8px;flex-wrap:wrap}
        .bcs-price-edit-0193{display:inline-flex!important;align-items:center;gap:5px}
        .bcs-price-lock-0193{display:block;margin-top:5px;color:#64748b;font-size:12px;font-weight:500}
        </style>
        <?php
    }

    public static function admin_scripts(): void {
        if (!is_admin() || !current_user_can('manage_options')) return;
        $page = sanitize_key(wp_unslash($_GET['page'] ?? ''));
        $id = absint($_GET['view'] ?? 0);
        if ($page !== 'bcs-registrations' || !$id) return;

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT r.total_amount,r.status,r.agreement_status,r.agreement_sent_at,a.status agreement_real_status FROM " . BCS_DB::table('registrations') . " r LEFT JOIN " . BCS_DB::table('agreements') . " a ON a.id=r.agreement_id WHERE r.id=%d",
            $id
        ));
        if (!$row) return;
        $locked = $row->status === 'cancelled'
            || in_array((string)$row->agreement_status, ['pending', 'accepted'], true)
            || in_array((string)$row->agreement_real_status, ['pending', 'accepted'], true)
            || !empty($row->agreement_sent_at);
        ?>
        <script>
        (function(){
            const registrationId=<?php echo (int)$id; ?>;
            const locked=<?php echo $locked ? 'true' : 'false'; ?>;
            const currentAmount=<?php echo wp_json_encode(number_format((float)$row->total_amount, 2, '.', '')); ?>;
            const nonce=<?php echo wp_json_encode(wp_create_nonce('bcs_update_registration_price_0193')); ?>;
            const ajax=<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;

            const summary=[...document.querySelectorAll('.bcs-stat-grid>div')].find(el=>{
                const label=el.querySelector('span');
                return label && label.textContent.trim()==='Płatność';
            });
            if(!summary)return;
            const value=summary.querySelector('strong');
            if(!value||summary.querySelector('.bcs-price-edit-0193'))return;

            const wrap=document.createElement('span');
            wrap.className='bcs-price-edit-wrap-0193';
            value.parentNode.insertBefore(wrap,value);
            wrap.appendChild(value);

            if(locked){
                const note=document.createElement('small');
                note.className='bcs-price-lock-0193';
                note.textContent='Cena zablokowana po wysłaniu umowy do podpisu.';
                summary.appendChild(note);
                return;
            }

            const button=document.createElement('button');
            button.type='button';
            button.className='button button-small bcs-price-edit-0193';
            button.innerHTML='<span class="dashicons dashicons-edit"></span> Edytuj cenę';
            wrap.appendChild(button);

            button.addEventListener('click',async()=>{
                const entered=window.prompt('Podaj nową cenę za turnus:',currentAmount.replace('.',','));
                if(entered===null)return;
                button.disabled=true;
                try{
                    const response=await fetch(ajax,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:new URLSearchParams({action:'bcs_update_registration_price_0193',nonce,registration_id:String(registrationId),amount:entered})});
                    const json=await response.json();
                    if(!response.ok||!json.success)throw new Error(json?.data?.message||'Nie udało się zmienić ceny.');
                    value.textContent=value.textContent.replace(/\d[\d\s]*[,.]\d{2}\s*zł\s*$/,json.data.amount);
                    if(!value.textContent.includes(json.data.amount)) value.textContent=json.data.amount;
                    alert(json.data.message);
                    location.reload();
                }catch(error){
                    alert(error.message);
                    button.disabled=false;
                }
            });
        })();
        </script>
        <?php
    }
}
