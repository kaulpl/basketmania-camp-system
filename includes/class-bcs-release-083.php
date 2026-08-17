<?php
if (!defined('ABSPATH')) exit;

/**
 * 0.83 – osobne, edytowalne dane do wystawienia faktury.
 *
 * Formularz Obozowy pozostaje historycznym źródłem danych podanych przez rodzica,
 * natomiast billing_* jest administracyjnym źródłem prawdy dla przyszłej faktury.
 * Do czasu wystawienia faktury administrator może przełączyć nabywcę pomiędzy
 * osobą prywatną i firmą oraz zmienić wszystkie dane. Po wystawieniu faktury profil
 * staje się tylko do odczytu.
 *
 * Dla kompatybilności z istniejącym generatorem 0.80/0.82 profil billing_* jest
 * podstawiany do historycznych pól invoice_* wyłącznie na czas generowania faktury
 * i KSeF, po czym oryginalne dane formularza są przywracane.
 */
final class BCS_Release_083 {
    private const DB_OPTION = 'bcs_release_083_db_version';
    private const DB_VERSION = '0.83';
    private const SAVE_ACTION = 'bcs_save_invoice_profile_083';

    public static function init(): void {
        self::maybe_upgrade();

        // Po zapisaniu pełnego Formularza Obozowego utwórz profil fakturowy po
        // zakończeniu requestu. Oryginalny handler kończy request przez exit/redirect,
        // dlatego inicjalizację uzbrajamy jako shutdown function przed nim.
        add_action('admin_post_nopriv_bcs_complete_registration', [__CLASS__, 'arm_profile_after_form_submit'], 1);
        add_action('admin_post_bcs_complete_registration', [__CLASS__, 'arm_profile_after_form_submit'], 1);

        add_action('wp_ajax_'.self::SAVE_ACTION, [__CLASS__, 'ajax_save_profile']);
        add_action('admin_footer', [__CLASS__, 'card_invoice_tab'], 9997);

        // 0.83 przejmuje wejścia generowania faktury, aby każda właściwa faktura
        // używała profilu z zakładki „Dane do Faktury”.
        remove_action('wp_ajax_bcs_ksef_generate_invoice_full_076', ['BCS_Release_082', 'ajax_real_generate'], -100);
        remove_action('wp_ajax_bcs_list_quick_action_02010', ['BCS_Release_082', 'ajax_list_generate'], -100);
        remove_action('wp_ajax_bcs_generate_invoice_0200', ['BCS_Release_082', 'ajax_legacy_generate'], -100);
        remove_action('admin_init', ['BCS_Release_082', 'classic_generate'], -100);
        remove_action('admin_post_bcs_workflow_single', ['BCS_Release_082', 'single_generate'], -100);

        add_action('wp_ajax_bcs_ksef_generate_invoice_full_076', [__CLASS__, 'ajax_real_generate'], -100);
        add_action('wp_ajax_bcs_list_quick_action_02010', [__CLASS__, 'ajax_list_generate'], -100);
        add_action('wp_ajax_bcs_generate_invoice_0200', [__CLASS__, 'ajax_legacy_generate'], -100);
        add_action('admin_init', [__CLASS__, 'classic_generate'], -100);
        add_action('admin_post_bcs_workflow_single', [__CLASS__, 'single_generate'], -100);
    }

    private static function add_column(string $table, string $column, string $definition): void {
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column));
        if ($exists === null) $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }

    private static function maybe_upgrade(): void {
        if ((string)get_option(self::DB_OPTION, '') === self::DB_VERSION) return;
        global $wpdb;
        $table = BCS_DB::table('registrations');
        self::add_column($table, 'billing_type', "VARCHAR(20) NULL");
        self::add_column($table, 'billing_name', "VARCHAR(190) NULL");
        self::add_column($table, 'billing_street', "VARCHAR(190) NULL");
        self::add_column($table, 'billing_postal_code', "VARCHAR(20) NULL");
        self::add_column($table, 'billing_city', "VARCHAR(120) NULL");
        self::add_column($table, 'billing_nip', "VARCHAR(20) NULL");
        self::add_column($table, 'billing_notes', "TEXT NULL");
        self::add_column($table, 'billing_source', "VARCHAR(30) NULL");
        self::add_column($table, 'billing_initialized_at', "DATETIME NULL");
        self::add_column($table, 'billing_updated_at', "DATETIME NULL");

        // Migracja istniejących, już przesłanych Formularzy Obozowych.
        $ids = array_map('intval', (array)$wpdb->get_col(
            "SELECT id FROM {$table} WHERE form_status='complete' AND (billing_initialized_at IS NULL OR billing_initialized_at='')"
        ));
        foreach ($ids as $id) self::initialize_profile($id, false);
        update_option(self::DB_OPTION, self::DB_VERSION, false);
    }

    public static function arm_profile_after_form_submit(): void {
        $id = absint($_POST['registration_id'] ?? 0);
        if ($id > 0) register_shutdown_function([__CLASS__, 'shutdown_initialize_profile'], $id);
    }

    public static function shutdown_initialize_profile(int $registrationId): void {
        self::initialize_profile($registrationId, false);
    }

    private static function parent_street(object $r): string {
        $structured = trim((string)($r->parent_street ?? '').' '.(string)($r->parent_house_number ?? ''));
        if ($structured !== '') return $structured;
        $address = trim((string)($r->parent_address ?? ''));
        if ($address === '') return '';
        $lines = preg_split('/\R+/u', $address) ?: [];
        return trim((string)($lines[0] ?? $address));
    }

    /** Tworzy profil tylko z danych przesłanego formularza; późniejsze edycje go nie nadpisują. */
    public static function initialize_profile(int $registrationId, bool $force = false): bool {
        global $wpdb;
        $table = BCS_DB::table('registrations');
        $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $registrationId));
        if (!$r || (string)($r->form_status ?? '') !== 'complete') return false;
        if (!$force && !empty($r->billing_initialized_at)) return true;

        // Za dane firmowe uznajemy świadomy wybór faktury i podany NIP.
        // Puste/stare pola firmowe przy odznaczonej fakturze nie mogą przejąć profilu.
        $company = (int)($r->invoice_requested ?? 0) === 1
            && trim((string)($r->invoice_nip ?? '')) !== '';
        $now = BCS_Utils::now();
        $data = $company ? [
            'billing_type'=>'company',
            'billing_name'=>trim((string)($r->invoice_buyer_name ?? '')),
            'billing_street'=>trim((string)($r->invoice_street ?? '')),
            'billing_postal_code'=>trim((string)($r->invoice_postal_code ?? '')),
            'billing_city'=>trim((string)($r->invoice_city ?? '')),
            'billing_nip'=>preg_replace('/\D+/', '', (string)($r->invoice_nip ?? '')) ?: '',
            'billing_notes'=>trim((string)($r->invoice_notes ?? '')),
            'billing_source'=>'form_company',
        ] : [
            'billing_type'=>'individual',
            'billing_name'=>trim((string)($r->parent_first_name ?? '').' '.(string)($r->parent_last_name ?? '')),
            'billing_street'=>self::parent_street($r),
            'billing_postal_code'=>trim((string)($r->parent_postal_code ?? '')),
            'billing_city'=>trim((string)($r->parent_city ?? '')),
            'billing_nip'=>'',
            'billing_notes'=>'',
            'billing_source'=>'form_individual',
        ];
        $data['billing_initialized_at'] = $now;
        $data['billing_updated_at'] = $now;
        $updated = $wpdb->update($table, $data, ['id'=>$registrationId]);
        if ($updated === false) return false;
        BCS_Utils::log('invoice_profile_initialized_083', [
            'billing_type'=>$data['billing_type'],
            'billing_source'=>$data['billing_source'],
        ], $registrationId, null);
        return true;
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

    private static function valid_profile(object $r): array {
        $type = in_array((string)($r->billing_type ?? ''), ['individual','company'], true) ? (string)$r->billing_type : '';
        $name = trim((string)($r->billing_name ?? ''));
        $street = trim((string)($r->billing_street ?? ''));
        $postal = trim((string)($r->billing_postal_code ?? ''));
        $city = trim((string)($r->billing_city ?? ''));
        $nip = preg_replace('/\D+/', '', (string)($r->billing_nip ?? '')) ?: '';
        $errors = [];
        if ($type === '') $errors[] = 'Wybierz typ nabywcy.';
        if ($name === '') $errors[] = 'Podaj nazwę firmy albo imię i nazwisko.';
        if ($street === '') $errors[] = 'Podaj ulicę i numer.';
        if ($postal === '') $errors[] = 'Podaj kod pocztowy.';
        if ($city === '') $errors[] = 'Podaj miejscowość.';
        if ($type === 'company' && strlen($nip) !== 10) $errors[] = 'Dla firmy podaj 10-cyfrowy NIP.';
        return compact('type','name','street','postal','city','nip','errors');
    }

    public static function ajax_save_profile(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Brak uprawnień.'], 403);
        $id = absint($_POST['registration_id'] ?? 0);
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        if (!$id || !wp_verify_nonce($nonce, 'bcs_invoice_profile_083_'.$id)) {
            wp_send_json_error(['message'=>'Sesja wygasła. Odśwież Kartę Zgłoszenia.'], 403);
        }
        self::initialize_profile($id, false);
        $r = self::registration($id);
        if (!$r || (string)($r->form_status ?? '') !== 'complete') {
            wp_send_json_error(['message'=>'Dane do faktury są dostępne po przesłaniu Formularza Obozowego.'], 409);
        }
        if (!empty($r->invoice_real_id)) {
            wp_send_json_error(['message'=>'Dane do faktury są zablokowane, ponieważ faktura została już wystawiona.'], 409);
        }

        $type = sanitize_key(wp_unslash($_POST['billing_type'] ?? ''));
        $candidate = clone $r;
        $candidate->billing_type = $type;
        $candidate->billing_name = sanitize_text_field(wp_unslash($_POST['billing_name'] ?? ''));
        $candidate->billing_street = sanitize_text_field(wp_unslash($_POST['billing_street'] ?? ''));
        $candidate->billing_postal_code = sanitize_text_field(wp_unslash($_POST['billing_postal_code'] ?? ''));
        $candidate->billing_city = sanitize_text_field(wp_unslash($_POST['billing_city'] ?? ''));
        $candidate->billing_nip = sanitize_text_field(wp_unslash($_POST['billing_nip'] ?? ''));
        $candidate->billing_notes = sanitize_textarea_field(wp_unslash($_POST['billing_notes'] ?? ''));
        $valid = self::valid_profile($candidate);
        if ($valid['errors']) wp_send_json_error(['message'=>implode(' ', $valid['errors'])], 422);

        $now = BCS_Utils::now();
        $data = [
            'billing_type'=>$valid['type'],
            'billing_name'=>$valid['name'],
            'billing_street'=>$valid['street'],
            'billing_postal_code'=>$valid['postal'],
            'billing_city'=>$valid['city'],
            'billing_nip'=>$valid['type'] === 'company' ? $valid['nip'] : '',
            'billing_notes'=>trim((string)$candidate->billing_notes),
            'billing_source'=>'admin_edit',
            'billing_updated_at'=>$now,
            'updated_at'=>$now,
        ];
        global $wpdb;
        $updated = $wpdb->update(BCS_DB::table('registrations'), $data, ['id'=>$id]);
        if ($updated === false) wp_send_json_error(['message'=>'Nie udało się zapisać danych do faktury.'], 500);
        BCS_Utils::log('invoice_profile_updated_083', [
            'billing_type'=>$data['billing_type'],
            'billing_source'=>'admin_edit',
        ], $id, null);
        wp_send_json_success(['message'=>'Dane do faktury zostały zapisane.']);
    }

    private static function source_label(string $source): string {
        return match ($source) {
            'form_company' => 'Dane firmowe z Formularza Obozowego',
            'form_individual' => 'Dane imienne rodzica / opiekuna',
            'admin_edit' => 'Zmienione ręcznie przez administratora',
            default => 'Profil danych do faktury',
        };
    }

    private static function panel_html(object $r): string {
        $locked = !empty($r->invoice_real_id);
        $type = (string)($r->billing_type ?? '') === 'company' ? 'Firma' : 'Osoba prywatna';
        $nip = trim((string)($r->billing_nip ?? ''));
        ob_start(); ?>
        <section class="bcs-panel bcs-invoice-profile-083" data-bcs-invoice-profile-083 hidden>
            <div class="bcs-invoice-profile-head-083">
                <div><h2>Dane do Faktury</h2><p>To są dane używane przy generowaniu PDF faktury i wysyłce do KSeF. Nie zmieniają danych osobowych zapisanych w Formularzu Obozowym.</p></div>
                <span class="bcs-badge"><?php echo esc_html(self::source_label((string)($r->billing_source ?? ''))); ?></span>
            </div>
            <?php if ($locked): ?>
                <div class="notice notice-info inline"><p><strong>Dane zablokowane.</strong> Faktura <?php echo esc_html((string)($r->invoice_number ?? '')); ?> została już wystawiona. Zmiana nabywcy wymaga osobnej procedury korekty.</p></div>
            <?php endif; ?>
            <div class="bcs-invoice-profile-view-083">
                <div><span>Typ nabywcy</span><strong><?php echo esc_html($type); ?></strong></div>
                <div><span>Nazwa / imię i nazwisko</span><strong><?php echo esc_html((string)($r->billing_name ?: '—')); ?></strong></div>
                <div><span>Ulica i numer</span><strong><?php echo esc_html((string)($r->billing_street ?: '—')); ?></strong></div>
                <div><span>Kod pocztowy</span><strong><?php echo esc_html((string)($r->billing_postal_code ?: '—')); ?></strong></div>
                <div><span>Miejscowość</span><strong><?php echo esc_html((string)($r->billing_city ?: '—')); ?></strong></div>
                <div><span>NIP</span><strong><?php echo esc_html($nip !== '' ? $nip : '—'); ?></strong></div>
                <div class="is-wide"><span>Uwagi do faktury</span><strong><?php echo nl2br(esc_html((string)($r->billing_notes ?: '—'))); ?></strong></div>
            </div>
            <?php if (!$locked): ?>
                <div class="bcs-invoice-profile-actions-083"><button type="button" class="button button-primary" data-bcs-invoice-edit-083><span class="dashicons dashicons-edit"></span> Edytuj dane do faktury</button></div>
                <form class="bcs-invoice-profile-form-083" data-bcs-invoice-form-083 hidden>
                    <div class="bcs-invoice-profile-shortcuts-083">
                        <button type="button" class="button" data-bcs-fill-parent-083>Użyj danych rodzica</button>
                        <?php if ((int)($r->invoice_requested ?? 0) === 1 && trim((string)($r->invoice_buyer_name ?? '')) !== ''): ?>
                            <button type="button" class="button" data-bcs-fill-company-083>Użyj danych firmowych z formularza</button>
                        <?php endif; ?>
                    </div>
                    <div class="bcs-invoice-profile-editor-grid-083">
                        <label><span>Typ nabywcy</span><select name="billing_type" required><option value="individual" <?php selected((string)$r->billing_type, 'individual'); ?>>Osoba prywatna</option><option value="company" <?php selected((string)$r->billing_type, 'company'); ?>>Firma</option></select></label>
                        <label><span>Nazwa / imię i nazwisko</span><input name="billing_name" value="<?php echo esc_attr((string)$r->billing_name); ?>" required></label>
                        <label><span>Ulica i numer</span><input name="billing_street" value="<?php echo esc_attr((string)$r->billing_street); ?>" required></label>
                        <label><span>Kod pocztowy</span><input name="billing_postal_code" value="<?php echo esc_attr((string)$r->billing_postal_code); ?>" required></label>
                        <label><span>Miejscowość</span><input name="billing_city" value="<?php echo esc_attr((string)$r->billing_city); ?>" required></label>
                        <label data-bcs-nip-row-083><span>NIP</span><input name="billing_nip" value="<?php echo esc_attr((string)$r->billing_nip); ?>" inputmode="numeric"></label>
                        <label class="is-wide"><span>Uwagi do faktury</span><textarea name="billing_notes" rows="4"><?php echo esc_textarea((string)$r->billing_notes); ?></textarea></label>
                    </div>
                    <div class="bcs-invoice-profile-form-actions-083"><button type="button" class="button" data-bcs-invoice-cancel-083>Anuluj</button><button type="submit" class="button button-primary">Zapisz dane do faktury</button></div>
                </form>
            <?php endif; ?>
        </section>
        <?php return (string)ob_get_clean();
    }

    public static function card_invoice_tab(): void {
        if (!current_user_can('manage_options') || !is_admin()) return;
        if (sanitize_key(wp_unslash($_GET['page'] ?? '')) !== 'bcs-registrations') return;
        $id = absint($_GET['view'] ?? 0);
        if (!$id) return;
        self::initialize_profile($id, false);
        $r = self::registration($id);
        if (!$r || (string)($r->form_status ?? '') !== 'complete' || empty($r->billing_initialized_at)) return;

        $panel = self::panel_html($r);
        $nonce = wp_create_nonce('bcs_invoice_profile_083_'.$id);
        $parent = [
            'type'=>'individual',
            'name'=>trim((string)$r->parent_first_name.' '.(string)$r->parent_last_name),
            'street'=>self::parent_street($r),
            'postal'=>(string)$r->parent_postal_code,
            'city'=>(string)$r->parent_city,
            'nip'=>'',
            'notes'=>'',
        ];
        $company = [
            'type'=>'company',
            'name'=>(string)$r->invoice_buyer_name,
            'street'=>(string)$r->invoice_street,
            'postal'=>(string)$r->invoice_postal_code,
            'city'=>(string)$r->invoice_city,
            'nip'=>preg_replace('/\D+/', '', (string)$r->invoice_nip) ?: '',
            'notes'=>(string)$r->invoice_notes,
        ];
        ?>
        <template id="bcs-invoice-profile-template-083"><?php echo $panel; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></template>
        <style>
            .bcs-card-data-tabs-083{display:flex;gap:6px;margin:16px 0 10px;padding:5px;background:#f0f0f1;border-radius:9px;width:max-content;max-width:100%}
            .bcs-card-data-tabs-083 button{border:0;background:transparent;padding:8px 14px;border-radius:7px;font-weight:600;cursor:pointer}
            .bcs-card-data-tabs-083 button.is-active{background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.12)}
            .bcs-invoice-profile-083{margin-top:0}
            .bcs-invoice-profile-head-083{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;margin-bottom:16px}
            .bcs-invoice-profile-head-083 h2{margin:0 0 5px}.bcs-invoice-profile-head-083 p{margin:0;color:#646970;max-width:760px}
            .bcs-invoice-profile-view-083,.bcs-invoice-profile-editor-grid-083{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
            .bcs-invoice-profile-view-083>div{padding:12px;border:1px solid #dcdcde;border-radius:8px;background:#fff}.bcs-invoice-profile-view-083 span,.bcs-invoice-profile-editor-grid-083 label>span{display:block;color:#646970;font-size:12px;margin-bottom:4px}.bcs-invoice-profile-view-083 .is-wide,.bcs-invoice-profile-editor-grid-083 .is-wide{grid-column:1/-1}
            .bcs-invoice-profile-editor-grid-083 input,.bcs-invoice-profile-editor-grid-083 select,.bcs-invoice-profile-editor-grid-083 textarea{width:100%}
            .bcs-invoice-profile-actions-083,.bcs-invoice-profile-form-actions-083,.bcs-invoice-profile-shortcuts-083{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}.bcs-invoice-profile-form-actions-083{justify-content:flex-end}
            .bcs-invoice-profile-form-083{margin-top:16px;padding-top:16px;border-top:1px solid #dcdcde}
            @media(max-width:782px){.bcs-invoice-profile-view-083,.bcs-invoice-profile-editor-grid-083{grid-template-columns:1fr}.bcs-invoice-profile-view-083 .is-wide,.bcs-invoice-profile-editor-grid-083 .is-wide{grid-column:auto}.bcs-invoice-profile-head-083{display:block}.bcs-invoice-profile-head-083 .bcs-badge{display:inline-block;margin-top:8px}}
        </style>
        <script>
        (()=>{
            const id=<?php echo (int)$id; ?>;
            const nonce=<?php echo wp_json_encode($nonce); ?>;
            const parentData=<?php echo wp_json_encode($parent, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
            const companyData=<?php echo wp_json_encode($company, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
            const notify=(m,ok=true)=>{if(typeof window.bcsNotify==='function')window.bcsNotify(m,ok);else if(typeof window.bcsPopup0190==='function')window.bcsPopup0190(m,ok);else window.alert(m);};

            // Formularz Obozowy zachowuje historyczne dane źródłowe, ale pola faktury
            // edytujemy wyłącznie w nowej zakładce.
            if(window.BCSCardForm060&&Array.isArray(window.BCSCardForm060.editorGroups)){
                window.BCSCardForm060.editorGroups=window.BCSCardForm060.editorGroups.filter(g=>String(g?.title||'').toLowerCase()!=='dane do faktury');
            }
            const removeLegacyInvoiceSection=()=>document.querySelectorAll('.bcs-card-form-section-060').forEach(sec=>{
                if(String(sec.querySelector('h3')?.textContent||'').trim().toLowerCase()==='dane do faktury')sec.remove();
            });

            const mount=()=>{
                removeLegacyInvoiceSection();
                if(document.querySelector('.bcs-card-data-tabs-083'))return;
                const formPanel=[...document.querySelectorAll('.bcs-accordion-panel')].find(p=>/Dane z formularza/i.test(p.querySelector('summary strong')?.textContent||''));
                const template=document.getElementById('bcs-invoice-profile-template-083');
                if(!formPanel||!template)return;
                const profile=template.content.firstElementChild.cloneNode(true);profile.hidden=true;
                const tabs=document.createElement('div');tabs.className='bcs-card-data-tabs-083';tabs.setAttribute('role','tablist');
                tabs.innerHTML='<button type="button" class="is-active" data-bcs-data-tab-083="form" role="tab">Formularz obozowy</button><button type="button" data-bcs-data-tab-083="invoice" role="tab">Dane do Faktury</button>';
                formPanel.insertAdjacentElement('beforebegin',tabs);formPanel.insertAdjacentElement('afterend',profile);
                const show=(name)=>{const invoice=name==='invoice';formPanel.hidden=invoice;profile.hidden=!invoice;tabs.querySelectorAll('button').forEach(b=>b.classList.toggle('is-active',b.dataset.bcsDataTab083===name));if(invoice)history.replaceState(null,'','#dane-do-faktury');else if(location.hash==='#dane-do-faktury')history.replaceState(null,'',location.pathname+location.search);};
                tabs.addEventListener('click',e=>{const b=e.target.closest('[data-bcs-data-tab-083]');if(b)show(b.dataset.bcsDataTab083);});
                if(location.hash==='#dane-do-faktury')show('invoice');
            };

            const setFormData=(form,data)=>{if(!form)return;const map={billing_type:'type',billing_name:'name',billing_street:'street',billing_postal_code:'postal',billing_city:'city',billing_nip:'nip',billing_notes:'notes'};Object.entries(map).forEach(([field,key])=>{const el=form.elements[field];if(el)el.value=data[key]??'';});toggleNip(form);};
            const toggleNip=(form)=>{const company=form?.elements?.billing_type?.value==='company';const row=form?.querySelector('[data-bcs-nip-row-083]');const input=form?.elements?.billing_nip;if(row)row.hidden=!company;if(input)input.required=company;if(!company&&input)input.value='';};

            document.addEventListener('click',e=>{
                const edit=e.target.closest('[data-bcs-invoice-edit-083]');if(edit){const panel=edit.closest('[data-bcs-invoice-profile-083]'),form=panel?.querySelector('[data-bcs-invoice-form-083]');if(form){form.hidden=false;edit.closest('.bcs-invoice-profile-actions-083').hidden=true;toggleNip(form);}return;}
                const cancel=e.target.closest('[data-bcs-invoice-cancel-083]');if(cancel){const panel=cancel.closest('[data-bcs-invoice-profile-083]'),form=panel?.querySelector('[data-bcs-invoice-form-083]');if(form)form.hidden=true;const actions=panel?.querySelector('.bcs-invoice-profile-actions-083');if(actions)actions.hidden=false;return;}
                const parent=e.target.closest('[data-bcs-fill-parent-083]');if(parent){setFormData(parent.closest('form'),parentData);return;}
                const company=e.target.closest('[data-bcs-fill-company-083]');if(company){setFormData(company.closest('form'),companyData);return;}
            },true);
            document.addEventListener('change',e=>{if(e.target.matches('[data-bcs-invoice-form-083] [name="billing_type"]'))toggleNip(e.target.closest('form'));},true);
            document.addEventListener('submit',async e=>{
                const form=e.target.closest('[data-bcs-invoice-form-083]');if(!form)return;e.preventDefault();e.stopImmediatePropagation();
                const submit=form.querySelector('button[type="submit"]');if(submit){submit.disabled=true;submit.textContent='Zapisywanie…';}
                const body=new URLSearchParams({action:<?php echo wp_json_encode(self::SAVE_ACTION); ?>,registration_id:String(id),nonce});new FormData(form).forEach((v,k)=>body.set(k,String(v)));
                try{const res=await fetch(window.ajaxurl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:body.toString()});const json=await res.json();if(!res.ok||!json.success)throw new Error(json?.data?.message||'Nie udało się zapisać danych do faktury.');notify(json.data?.message||'Dane do faktury zostały zapisane.',true);location.hash='dane-do-faktury';window.setTimeout(()=>window.location.reload(),450);}catch(err){notify(err.message||'Nie udało się zapisać danych do faktury.',false);if(submit){submit.disabled=false;submit.textContent='Zapisz dane do faktury';}}
            },true);

            if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount,{once:true});else mount();
            window.setTimeout(mount,150);window.setTimeout(mount,600);new MutationObserver(()=>{removeLegacyInvoiceSection();mount();}).observe(document.body,{childList:true,subtree:true});
        })();
        </script>
        <?php
    }

    /**
     * Most kompatybilnościowy: billing_* jest jedynym źródłem prawdy, ale istniejący
     * generator 0.80/0.82 czyta invoice_*. Podstawiamy więc profil tylko na czas
     * wykonania pełnej procedury i zawsze odtwarzamy oryginalny formularz.
     */
    public static function generate_guarded(int $registrationId): array {
        self::initialize_profile($registrationId, false);
        $r = self::registration($registrationId);
        if (!$r || empty($r->billing_initialized_at)) return ['success'=>false,'message'=>'Brak przygotowanych danych do faktury. Otwórz Kartę Zgłoszenia i uzupełnij zakładkę „Dane do Faktury”.'];
        $valid = self::valid_profile($r);
        if ($valid['errors']) return ['success'=>false,'message'=>'Dane do faktury są niekompletne: '.implode(' ', $valid['errors'])];

        $original = [
            'invoice_requested'=>(int)($r->invoice_requested ?? 0),
            'invoice_buyer_name'=>(string)($r->invoice_buyer_name ?? ''),
            'invoice_street'=>(string)($r->invoice_street ?? ''),
            'invoice_postal_code'=>(string)($r->invoice_postal_code ?? ''),
            'invoice_city'=>(string)($r->invoice_city ?? ''),
            'invoice_nip'=>(string)($r->invoice_nip ?? ''),
            'invoice_notes'=>(string)($r->invoice_notes ?? ''),
        ];
        $compat = [
            'invoice_requested'=>1,
            'invoice_buyer_name'=>$valid['name'],
            'invoice_street'=>$valid['street'],
            'invoice_postal_code'=>$valid['postal'],
            'invoice_city'=>$valid['city'],
            'invoice_nip'=>$valid['type'] === 'company' ? $valid['nip'] : '',
            'invoice_notes'=>(string)($r->billing_notes ?? ''),
        ];
        global $wpdb;
        $updated = $wpdb->update(BCS_DB::table('registrations'), $compat, ['id'=>$registrationId]);
        if ($updated === false) return ['success'=>false,'message'=>'Nie udało się przygotować danych nabywcy do wygenerowania faktury.'];
        try {
            $result = BCS_Release_082::generate_guarded($registrationId);
            BCS_Utils::log('invoice_generated_from_profile_083', [
                'billing_type'=>$valid['type'],
                'billing_source'=>(string)($r->billing_source ?? ''),
                'success'=>!empty($result['success']),
            ], $registrationId, null);
            return $result;
        } finally {
            $wpdb->update(BCS_DB::table('registrations'), $original, ['id'=>$registrationId]);
        }
    }

    private static function json_result(array $result): void {
        !empty($result['success']) ? wp_send_json_success($result) : wp_send_json_error($result, 422);
    }

    public static function ajax_real_generate(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Brak uprawnień.'], 403);
        $id = absint($_POST['registration_id'] ?? 0);
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        if (!$id || !wp_verify_nonce($nonce, 'bcs_crm_'.$id)) wp_send_json_error(['message'=>'Sesja wygasła. Odśwież stronę.'], 403);
        self::json_result(self::generate_guarded($id));
    }

    public static function ajax_list_generate(): void {
        if (sanitize_key(wp_unslash($_POST['quick_action'] ?? '')) !== 'invoice_generate') return;
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Brak uprawnień.'], 403);
        $id = absint($_POST['registration_id'] ?? 0);
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        $valid = $id && (
            wp_verify_nonce($nonce, 'bcs_crm_'.$id)
            || wp_verify_nonce($nonce, 'bcs_workflow_single_'.$id.'_invoice_generate')
            || wp_verify_nonce($nonce, 'bcs_workflow_single_'.$id.'_generate_invoice')
        );
        if (!$valid) wp_send_json_error(['message'=>'Sesja wygasła.'], 403);
        self::json_result(self::generate_guarded($id));
    }

    public static function ajax_legacy_generate(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Brak uprawnień.'], 403);
        check_ajax_referer('bcs_generate_invoice_0200', 'nonce');
        $id = absint($_POST['registration_id'] ?? 0);
        if (!$id) wp_send_json_error(['message'=>'Nieprawidłowe zgłoszenie.'], 422);
        self::json_result(self::generate_guarded($id));
    }

    public static function classic_generate(): void {
        if (!is_admin() || !current_user_can('manage_options')) return;
        if (!empty($_POST['bcs_crm_action']) && sanitize_key(wp_unslash($_POST['bcs_crm_action'])) === 'invoice_generate') {
            $id = absint($_POST['registration_id'] ?? 0);
            check_admin_referer('bcs_crm_'.$id);
            $result = self::generate_guarded($id);
            set_transient('bcs_ksef_invoice_result_'.get_current_user_id().'_'.$id, $result, 5 * MINUTE_IN_SECONDS);
            wp_safe_redirect(add_query_arg(['page'=>'bcs-registrations','view'=>$id,'crm_done'=>!empty($result['success'])?1:0], admin_url('admin.php')));
            exit;
        }
        if (!empty($_POST['bcs_workflow_action']) && sanitize_key(wp_unslash($_POST['bcs_workflow_action'])) === 'generate_invoice') {
            check_admin_referer('bcs_workflow_action');
            $ids = array_values(array_filter(array_map('absint', (array)($_POST['registration_ids'] ?? []))));
            $ok = 0; $failed = 0;
            foreach ($ids as $id) !empty(self::generate_guarded($id)['success']) ? $ok++ : $failed++;
            wp_safe_redirect(add_query_arg(['page'=>'bcs-registrations','done'=>$ok,'failed'=>$failed], admin_url('admin.php')));
            exit;
        }
    }

    public static function single_generate(): void {
        if (!current_user_can('manage_options')) return;
        $action = sanitize_key(wp_unslash($_GET['workflow'] ?? ''));
        if ($action !== 'generate_invoice') return;
        $id = absint($_GET['registration_id'] ?? 0);
        check_admin_referer('bcs_workflow_single_'.$id.'_generate_invoice');
        $result = self::generate_guarded($id);
        wp_safe_redirect(add_query_arg(['page'=>'bcs-registrations','view'=>$id,'done'=>!empty($result['success'])?1:0,'failed'=>empty($result['success'])?1:0], admin_url('admin.php')));
        exit;
    }
}
