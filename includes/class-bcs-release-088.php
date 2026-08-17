<?php
if (!defined('ABSPATH')) exit;

/**
 * 0.88 – jedno pole dodatkowe faktury: Dodatkowy opis do KSeF.
 *
 * Historyczne invoice_notes / billing_notes pozostają w bazie dla zgodności i audytu,
 * ale nie są już prezentowane ani używane podczas generowania nowych faktur.
 * Rodzic podaje invoice_ksef_description, a administrator przed wystawieniem faktury
 * może zmienić jego roboczą kopię billing_ksef_description.
 */
final class BCS_Release_088 {
    private const SAVE_ACTION = 'bcs_save_invoice_profile_087';

    public static function init(): void {
        // Zastępujemy UI 0.87 wersją z jednym polem dodatkowym.
        remove_action('admin_footer', ['BCS_Release_087', 'render_invoice_accordion'], 9998);
        add_action('admin_footer', [__CLASS__, 'render_invoice_accordion'], 9998);

        // Zastępujemy pole rodzica z 0.84: usuwamy stare invoice_notes i zostawiamy
        // wyłącznie invoice_ksef_description.
        remove_action('wp_footer', ['BCS_Release_084', 'parent_form_description_field'], 9999);
        add_action('wp_footer', [__CLASS__, 'parent_form_description_field'], 9999);

        // Wszystkie realne wejścia generowania przechodzą przez wrapper 0.88, który
        // gwarantuje, że historyczne billing_notes nie trafią do PDF/KSeF.
        remove_action('wp_ajax_bcs_ksef_generate_invoice_full_076', ['BCS_Release_084', 'ajax_real_generate'], -100);
        remove_action('wp_ajax_bcs_list_quick_action_02010', ['BCS_Release_084', 'ajax_list_generate'], -100);
        remove_action('wp_ajax_bcs_generate_invoice_0200', ['BCS_Release_084', 'ajax_legacy_generate'], -100);
        remove_action('admin_init', ['BCS_Release_084', 'classic_generate'], -100);
        remove_action('admin_post_bcs_workflow_single', ['BCS_Release_084', 'single_generate'], -100);

        add_action('wp_ajax_bcs_ksef_generate_invoice_full_076', [__CLASS__, 'ajax_real_generate'], -100);
        add_action('wp_ajax_bcs_list_quick_action_02010', [__CLASS__, 'ajax_list_generate'], -100);
        add_action('wp_ajax_bcs_generate_invoice_0200', [__CLASS__, 'ajax_legacy_generate'], -100);
        add_action('admin_init', [__CLASS__, 'classic_generate'], -100);
        add_action('admin_post_bcs_workflow_single', [__CLASS__, 'single_generate'], -100);
    }

    private static function is_registration_card(): bool {
        return is_admin()
            && current_user_can('manage_options')
            && sanitize_key(wp_unslash($_GET['page'] ?? '')) === 'bcs-registrations'
            && absint($_GET['view'] ?? 0) > 0;
    }

    private static function registration(int $registrationId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT r.*, i.id invoice_real_id, i.invoice_number '
            .'FROM '.BCS_DB::table('registrations').' r '
            .'LEFT JOIN '.BCS_DB::table('invoices').' i ON i.id=(SELECT i2.id FROM '.BCS_DB::table('invoices').' i2 WHERE i2.registration_id=r.id ORDER BY i2.id DESC LIMIT 1) '
            .'WHERE r.id=%d',
            $registrationId
        )) ?: null;
    }

    private static function source_label(string $source): string {
        return match ($source) {
            'form_company' => 'Dane firmowe z Formularza Obozowego',
            'form_individual' => 'Dane imienne rodzica / opiekuna',
            'admin_edit' => 'Zmienione ręcznie przez administratora',
            default => 'Dane imienne rodzica / opiekuna',
        };
    }

    private static function clean_description(string $value): string {
        $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '');
        if (function_exists('mb_substr')) return mb_substr($value, 0, 256, 'UTF-8');
        return substr($value, 0, 256);
    }

    public static function parent_form_description_field(): void {
        if (is_admin()) return;
        $token = sanitize_text_field(wp_unslash($_GET['token'] ?? ''));
        if ($token === '') return;

        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare(
            'SELECT child_first_name,child_last_name,invoice_ksef_description FROM '.BCS_DB::table('registrations').' WHERE public_token=%s LIMIT 1',
            $token
        ));
        if (!$r) return;

        $value = self::clean_description((string)($r->invoice_ksef_description ?? ''));
        if ($value === '') $value = self::clean_description(trim((string)$r->child_first_name.' '.(string)$r->child_last_name));
        ?>
        <script>
        (()=>{
            const form=document.querySelector('.bcs-camp-form');
            if(!form)return;

            // 0.88: stare „Dodatkowe dane na fakturze” nie jest już aktywnym polem.
            const legacy=form.querySelector('[name="invoice_notes"]');
            if(legacy){const holder=legacy.closest('label');if(holder)holder.remove();else legacy.remove();}

            if(form.elements.invoice_ksef_description)return;
            const section=[...form.querySelectorAll('.bcs-form-section')].find(s=>/dane do faktury/i.test(s.querySelector('h3')?.textContent||''));
            const grid=section?.querySelector('.bcs-grid');
            if(!grid)return;

            const label=document.createElement('label');label.className='bcs-span bcs-ksef-description-088';
            const title=document.createElement('span');title.textContent='Dodatkowy opis do KSeF';
            const textarea=document.createElement('textarea');textarea.name='invoice_ksef_description';textarea.rows=3;textarea.maxLength=256;textarea.value=<?php echo wp_json_encode($value); ?>;
            const small=document.createElement('small');small.textContent='Np. imię i nazwisko uczestnika obozu. To jedyne dodatkowe pole tekstowe przekazywane do KSeF (maks. 256 znaków).';
            label.append(title,textarea,small);grid.appendChild(label);
        })();
        </script>
        <?php
    }

    public static function render_invoice_accordion(): void {
        if (!self::is_registration_card()) return;
        $id = absint($_GET['view'] ?? 0);
        $r = BCS_Release_087::ensure_profile($id);
        if (!$r) return;

        $locked = !empty($r->invoice_real_id);
        $typeLabel = (string)($r->billing_type ?? '') === 'company' ? 'Firma' : 'Osoba prywatna';
        $notice = get_transient('bcs_invoice_087_notice_'.get_current_user_id().'_'.$id);
        if (is_array($notice)) delete_transient('bcs_invoice_087_notice_'.get_current_user_id().'_'.$id);
        ?>
        <section id="bcs-invoice-data-088" class="bcs-panel bcs-accordion-panel bcs-invoice-profile-088">
            <details <?php echo is_array($notice) ? 'open' : ''; ?>>
                <summary>
                    <span><span class="dashicons dashicons-money-alt"></span><strong>Dane do Faktury</strong></span>
                    <span class="bcs-accordion-statuses"><span class="bcs-badge"><?php echo esc_html(self::source_label((string)($r->billing_source ?? ''))); ?></span><span class="bcs-accordion-hint">Rozwiń dane</span></span>
                </summary>
                <div class="bcs-accordion-content">
                    <p class="bcs-muted">Dane używane do wystawienia faktury PDF i wysyłki do KSeF. Jedynym dodatkowym polem tekstowym jest „Dodatkowy opis do KSeF”.</p>
                    <?php if (is_array($notice)): ?>
                        <div class="notice <?php echo !empty($notice['ok']) ? 'notice-success' : 'notice-error'; ?> inline"><p><?php echo esc_html((string)($notice['message'] ?? '')); ?></p></div>
                    <?php endif; ?>
                    <?php if ($locked): ?>
                        <div class="notice notice-info inline"><p><strong>Dane zablokowane.</strong> Faktura <?php echo esc_html((string)($r->invoice_number ?? '')); ?> została już wystawiona. Zmiana nabywcy wymaga osobnej procedury korekty.</p></div>
                    <?php endif; ?>

                    <div class="bcs-detail-grid bcs-form-preview-grid bcs-invoice-preview-088">
                        <div class="bcs-detail-item"><span>Typ nabywcy</span><strong><?php echo esc_html($typeLabel); ?></strong></div>
                        <div class="bcs-detail-item"><span>Nazwa / imię i nazwisko</span><strong><?php echo esc_html((string)($r->billing_name ?: '—')); ?></strong></div>
                        <div class="bcs-detail-item"><span>Ulica i numer</span><strong><?php echo esc_html((string)($r->billing_street ?: '—')); ?></strong></div>
                        <div class="bcs-detail-item"><span>Kod pocztowy</span><strong><?php echo esc_html((string)($r->billing_postal_code ?: '—')); ?></strong></div>
                        <div class="bcs-detail-item"><span>Miejscowość</span><strong><?php echo esc_html((string)($r->billing_city ?: '—')); ?></strong></div>
                        <div class="bcs-detail-item"><span>NIP</span><strong><?php echo esc_html((string)($r->billing_nip ?: '—')); ?></strong></div>
                        <div class="bcs-detail-item bcs-detail-wide"><span>Dodatkowy opis do KSeF</span><strong><?php echo nl2br(esc_html((string)($r->billing_ksef_description ?: '—'))); ?></strong></div>
                    </div>

                    <?php if (!$locked): ?>
                        <details class="bcs-document-stage is-ready bcs-invoice-editor-088">
                            <summary>
                                <span><span class="dashicons dashicons-edit"></span><span><strong>Edytuj dane do faktury</strong><small>Zmień nabywcę lub dodatkowy opis przed wystawieniem faktury.</small></span></span>
                                <span class="bcs-stage-status">Rozwiń edycję</span>
                            </summary>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="bcs-invoice-form-088">
                                <input type="hidden" name="action" value="<?php echo esc_attr(self::SAVE_ACTION); ?>">
                                <input type="hidden" name="registration_id" value="<?php echo (int)$id; ?>">
                                <?php wp_nonce_field('bcs_invoice_profile_087_'.$id); ?>
                                <div class="bcs-invoice-grid-088">
                                    <label><span>Typ nabywcy</span><select name="billing_type" required><option value="individual" <?php selected((string)$r->billing_type, 'individual'); ?>>Osoba prywatna</option><option value="company" <?php selected((string)$r->billing_type, 'company'); ?>>Firma</option></select></label>
                                    <label><span>Nazwa / imię i nazwisko</span><input name="billing_name" value="<?php echo esc_attr((string)$r->billing_name); ?>" required></label>
                                    <label><span>Ulica i numer</span><input name="billing_street" value="<?php echo esc_attr((string)$r->billing_street); ?>" required></label>
                                    <label><span>Kod pocztowy</span><input name="billing_postal_code" value="<?php echo esc_attr((string)$r->billing_postal_code); ?>" required></label>
                                    <label><span>Miejscowość</span><input name="billing_city" value="<?php echo esc_attr((string)$r->billing_city); ?>" required></label>
                                    <label><span>NIP firmy</span><input name="billing_nip" inputmode="numeric" value="<?php echo esc_attr((string)$r->billing_nip); ?>"><small>Wymagany tylko dla firmy – dokładnie 10 cyfr.</small></label>
                                    <label class="is-wide"><span>Dodatkowy opis do KSeF</span><textarea name="billing_ksef_description" rows="3" maxlength="256"><?php echo esc_textarea((string)$r->billing_ksef_description); ?></textarea><small>Np. imię i nazwisko uczestnika obozu. Maks. 256 znaków.</small></label>
                                </div>
                                <p><button type="submit" class="button button-primary"><span class="dashicons dashicons-saved"></span> Zapisz dane do faktury</button></p>
                            </form>
                        </details>
                    <?php endif; ?>
                </div>
            </details>
        </section>
        <style>
            .bcs-invoice-profile-088{min-width:0;max-width:100%;box-sizing:border-box}
            .bcs-invoice-grid-088{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;padding:16px 0;min-width:0}
            .bcs-invoice-grid-088 label{display:block;min-width:0}.bcs-invoice-grid-088 label>span{display:block;font-weight:600;margin-bottom:5px}
            .bcs-invoice-grid-088 input,.bcs-invoice-grid-088 select,.bcs-invoice-grid-088 textarea{width:100%;max-width:100%;box-sizing:border-box}
            .bcs-invoice-grid-088 .is-wide{grid-column:1/-1}.bcs-invoice-grid-088 small{display:block;color:#646970;margin-top:4px}
            @media(max-width:782px){.bcs-invoice-grid-088{grid-template-columns:1fr}.bcs-invoice-grid-088 .is-wide{grid-column:auto}}
        </style>
        <script>
        (()=>{
            const section=document.getElementById('bcs-invoice-data-088');if(!section)return;
            const panels=[...document.querySelectorAll('.bcs-accordion-panel')].filter(panel=>panel!==section);
            const formPanel=panels.find(panel=>/Dane z formularza/i.test(panel.querySelector('summary strong')?.textContent||''));
            if(formPanel)formPanel.insertAdjacentElement('afterend',section);

            const stripLegacy=()=>{
                if(Array.isArray(window.BCSCardForm060?.editorGroups)){
                    window.BCSCardForm060.editorGroups.forEach(group=>{
                        if(Array.isArray(group?.fields))group.fields=group.fields.filter(field=>field?.name!=='invoice_notes');
                    });
                }
                document.querySelectorAll('[name="invoice_notes"]').forEach(field=>{const holder=field.closest('label');if(holder)holder.remove();else field.remove();});
                document.querySelectorAll('.bcs-detail-item').forEach(item=>{
                    if(String(item.querySelector('span')?.textContent||'').trim().toLowerCase()==='dodatkowe dane na fakturze')item.remove();
                });
            };
            stripLegacy();
            document.addEventListener('click',event=>{
                if(!event.target.closest('.bcs-card-form-edit-060'))return;
                [80,250,600].forEach(delay=>window.setTimeout(stripLegacy,delay));
            },true);
        })();
        </script>
        <?php
    }

    /**
     * Realna generacja nadal używa sprawdzonego pipeline 0.84, ale na czas generowania
     * czyścimy billing_notes. Dzięki temu jedynym tekstem dodatkowym w dokumencie jest
     * billing_ksef_description -> Fa/DodatkowyOpis.
     */
    public static function generate_guarded(int $registrationId): array {
        $r = BCS_Release_087::ensure_profile($registrationId);
        if (!$r) return ['success'=>false,'message'=>'Nie znaleziono zgłoszenia.'];

        $originalNotes = (string)($r->billing_notes ?? '');
        global $wpdb;
        if ($wpdb->update(BCS_DB::table('registrations'), ['billing_notes'=>''], ['id'=>$registrationId]) === false) {
            return ['success'=>false,'message'=>'Nie udało się przygotować danych faktury.'];
        }

        try {
            return BCS_Release_084::generate_guarded($registrationId);
        } finally {
            $wpdb->update(BCS_DB::table('registrations'), ['billing_notes'=>$originalNotes], ['id'=>$registrationId]);
        }
    }

    private static function json_result(array $result): void {
        if (!empty($result['success'])) wp_send_json_success($result);
        wp_send_json_error(['message'=>(string)($result['message'] ?? 'Nie udało się wygenerować faktury.')], 409);
    }

    public static function ajax_real_generate(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Brak uprawnień.'], 403);
        $id=absint($_POST['registration_id']??0);$nonce=sanitize_text_field(wp_unslash($_POST['nonce']??''));
        if(!$id||!wp_verify_nonce($nonce,'bcs_crm_'.$id))wp_send_json_error(['message'=>'Sesja wygasła. Odśwież stronę.'],403);
        self::json_result(self::generate_guarded($id));
    }

    public static function ajax_list_generate(): void {
        if(sanitize_key(wp_unslash($_POST['quick_action']??''))!=='invoice_generate')return;
        if(!current_user_can('manage_options'))wp_send_json_error(['message'=>'Brak uprawnień.'],403);
        $id=absint($_POST['registration_id']??0);$nonce=sanitize_text_field(wp_unslash($_POST['nonce']??''));
        $valid=$id&&(wp_verify_nonce($nonce,'bcs_crm_'.$id)||wp_verify_nonce($nonce,'bcs_workflow_single_'.$id.'_invoice_generate')||wp_verify_nonce($nonce,'bcs_workflow_single_'.$id.'_generate_invoice'));
        if(!$valid)wp_send_json_error(['message'=>'Sesja wygasła.'],403);
        self::json_result(self::generate_guarded($id));
    }

    public static function ajax_legacy_generate(): void {
        if(!current_user_can('manage_options'))wp_send_json_error(['message'=>'Brak uprawnień.'],403);
        check_ajax_referer('bcs_generate_invoice_0200','nonce');
        $id=absint($_POST['registration_id']??0);
        if(!$id)wp_send_json_error(['message'=>'Nieprawidłowe zgłoszenie.'],422);
        self::json_result(self::generate_guarded($id));
    }

    public static function classic_generate(): void {
        if(!is_admin()||!current_user_can('manage_options'))return;
        if(!empty($_POST['bcs_crm_action'])&&sanitize_key(wp_unslash($_POST['bcs_crm_action']))==='invoice_generate'){
            $id=absint($_POST['registration_id']??0);check_admin_referer('bcs_crm_'.$id);
            $result=self::generate_guarded($id);
            set_transient('bcs_ksef_invoice_result_'.get_current_user_id().'_'.$id,$result,5*MINUTE_IN_SECONDS);
            wp_safe_redirect(add_query_arg(['page'=>'bcs-registrations','view'=>$id,'crm_done'=>!empty($result['success'])?1:0],admin_url('admin.php')));exit;
        }
        if(!empty($_POST['bcs_workflow_action'])&&sanitize_key(wp_unslash($_POST['bcs_workflow_action']))==='generate_invoice'){
            check_admin_referer('bcs_workflow_action');
            $ids=array_values(array_filter(array_map('absint',(array)($_POST['registration_ids']??[]))));$ok=0;$failed=0;
            foreach($ids as $id)!empty(self::generate_guarded($id)['success'])?$ok++:$failed++;
            wp_safe_redirect(add_query_arg(['page'=>'bcs-registrations','done'=>$ok,'failed'=>$failed],admin_url('admin.php')));exit;
        }
    }

    public static function single_generate(): void {
        if(!current_user_can('manage_options'))return;
        $action=sanitize_key(wp_unslash($_GET['workflow']??''));if($action!=='generate_invoice')return;
        $id=absint($_GET['registration_id']??0);check_admin_referer('bcs_workflow_single_'.$id.'_generate_invoice');
        $result=self::generate_guarded($id);
        wp_safe_redirect(add_query_arg(['page'=>'bcs-registrations','view'=>$id,'done'=>!empty($result['success'])?1:0,'failed'=>empty($result['success'])?1:0],admin_url('admin.php')));exit;
    }
}
