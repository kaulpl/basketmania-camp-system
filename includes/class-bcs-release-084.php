<?php
if (!defined('ABSPATH')) exit;

/**
 * 0.84 – edytowalny dodatkowy opis faktury wysyłany do KSeF jako Fa/DodatkowyOpis.
 *
 * invoice_ksef_description zachowuje wartość przesłaną przez rodzica w Formularzu Obozowym.
 * billing_ksef_description jest bieżącą wartością administracyjną używaną przy wystawieniu
 * właściwej faktury. Po wystawieniu faktury profil pozostaje zablokowany jak w 0.83.
 */
final class BCS_Release_084 {
    private const DB_OPTION = 'bcs_release_084_db_version';
    private const DB_VERSION = '0.84';
    private const SAVE_ACTION = 'bcs_save_invoice_profile_083';
    private const DESCRIPTION_KEY = 'Dodatkowy opis';
    private const MAX_DESCRIPTION = 256;

    public static function init(): void {
        self::maybe_upgrade();

        add_action('admin_post_nopriv_bcs_complete_registration', [__CLASS__, 'arm_description_after_form_submit'], 2);
        add_action('admin_post_bcs_complete_registration', [__CLASS__, 'arm_description_after_form_submit'], 2);
        add_action('wp_footer', [__CLASS__, 'parent_form_description_field'], 9999);

        // Rozszerzamy zapis zakładki 0.83 o osobne pole opisu KSeF.
        remove_action('wp_ajax_'.self::SAVE_ACTION, ['BCS_Release_083', 'ajax_save_profile']);
        add_action('wp_ajax_'.self::SAVE_ACTION, [__CLASS__, 'ajax_save_profile']);
        add_action('admin_footer', [__CLASS__, 'card_description_ui'], 9999);

        // 0.84 przejmuje wszystkie wejścia generowania faktury z 0.83, ponieważ przed
        // wysyłką musi dopisać DodatkowyOpis do już przygotowanego XML FA(3).
        remove_action('wp_ajax_bcs_ksef_generate_invoice_full_076', ['BCS_Release_083', 'ajax_real_generate'], -100);
        remove_action('wp_ajax_bcs_list_quick_action_02010', ['BCS_Release_083', 'ajax_list_generate'], -100);
        remove_action('wp_ajax_bcs_generate_invoice_0200', ['BCS_Release_083', 'ajax_legacy_generate'], -100);
        remove_action('admin_init', ['BCS_Release_083', 'classic_generate'], -100);
        remove_action('admin_post_bcs_workflow_single', ['BCS_Release_083', 'single_generate'], -100);

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

    private static function clean_description(string $value): string {
        $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '');
        if (function_exists('mb_substr')) return mb_substr($value, 0, self::MAX_DESCRIPTION, 'UTF-8');
        return substr($value, 0, self::MAX_DESCRIPTION);
    }

    private static function fallback_description(object $r): string {
        $notes = self::clean_description((string)($r->invoice_notes ?? ''));
        if ($notes !== '') return $notes;
        return self::clean_description(trim((string)($r->child_first_name ?? '').' '.(string)($r->child_last_name ?? '')));
    }

    private static function maybe_upgrade(): void {
        if ((string)get_option(self::DB_OPTION, '') === self::DB_VERSION) return;
        global $wpdb;
        $table = BCS_DB::table('registrations');
        self::add_column($table, 'invoice_ksef_description', "TEXT NULL");
        self::add_column($table, 'billing_ksef_description', "TEXT NULL");

        $rows = $wpdb->get_results("SELECT id,form_status,child_first_name,child_last_name,invoice_notes,invoice_ksef_description,billing_ksef_description FROM {$table} WHERE form_status='complete'");
        foreach ((array)$rows as $r) {
            $submitted = self::clean_description((string)($r->invoice_ksef_description ?? ''));
            if ($submitted === '') $submitted = self::fallback_description($r);
            $billing = self::clean_description((string)($r->billing_ksef_description ?? ''));
            $data = [];
            if (trim((string)($r->invoice_ksef_description ?? '')) === '') $data['invoice_ksef_description'] = $submitted;
            if ($billing === '') $data['billing_ksef_description'] = $submitted;
            if ($data) $wpdb->update($table, $data, ['id'=>(int)$r->id]);
        }
        update_option(self::DB_OPTION, self::DB_VERSION, false);
    }

    public static function arm_description_after_form_submit(): void {
        $id = absint($_POST['registration_id'] ?? 0);
        if ($id <= 0) return;
        $description = self::clean_description(sanitize_textarea_field(wp_unslash($_POST['invoice_ksef_description'] ?? '')));
        if ($description === '') {
            $description = self::clean_description(trim(
                sanitize_text_field(wp_unslash($_POST['child_first_name'] ?? '')).' '.
                sanitize_text_field(wp_unslash($_POST['child_last_name'] ?? ''))
            ));
        }
        register_shutdown_function([__CLASS__, 'shutdown_save_form_description'], $id, $description);
    }

    public static function shutdown_save_form_description(int $registrationId, string $description): void {
        global $wpdb;
        $table = BCS_DB::table('registrations');
        $r = $wpdb->get_row($wpdb->prepare("SELECT billing_source,billing_ksef_description FROM {$table} WHERE id=%d", $registrationId));
        if (!$r) return;
        $description = self::clean_description($description);
        $data = ['invoice_ksef_description'=>$description];
        if ((string)($r->billing_source ?? '') !== 'admin_edit' || trim((string)($r->billing_ksef_description ?? '')) === '') {
            $data['billing_ksef_description'] = $description;
        }
        $wpdb->update($table, $data, ['id'=>$registrationId]);
        BCS_Utils::log('invoice_ksef_description_from_form_084', [
            'description_present'=>$description !== '',
            'length'=>function_exists('mb_strlen') ? mb_strlen($description, 'UTF-8') : strlen($description),
        ], $registrationId, null);
    }

    public static function parent_form_description_field(): void {
        if (is_admin()) return;
        $token = sanitize_text_field(wp_unslash($_GET['token'] ?? ''));
        if ($token === '') return;
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare(
            'SELECT id,child_first_name,child_last_name,invoice_ksef_description FROM '.BCS_DB::table('registrations').' WHERE public_token=%s LIMIT 1',
            $token
        ));
        if (!$r) return;
        $value = self::clean_description((string)($r->invoice_ksef_description ?? ''));
        if ($value === '') $value = self::clean_description(trim((string)$r->child_first_name.' '.(string)$r->child_last_name));
        ?>
        <script>
        (()=>{
            const value=<?php echo wp_json_encode($value); ?>;
            const mount=()=>{
                const form=document.querySelector('.bcs-camp-form');if(!form||form.elements.invoice_ksef_description)return;
                const section=[...form.querySelectorAll('.bcs-form-section')].find(s=>/dane do faktury/i.test(s.querySelector('h3')?.textContent||''));if(!section)return;
                const grid=section.querySelector('.bcs-grid');if(!grid)return;
                const label=document.createElement('label');label.className='bcs-span bcs-ksef-description-084';
                const title=document.createTextNode('Dodatkowy opis do KSeF');label.appendChild(title);
                const textarea=document.createElement('textarea');textarea.name='invoice_ksef_description';textarea.rows=3;textarea.maxLength=256;textarea.value=value;label.appendChild(textarea);
                const small=document.createElement('small');small.textContent='Np. imię i nazwisko uczestnika obozu. Tekst trafi do pola DodatkowyOpis w KSeF (maks. 256 znaków).';label.appendChild(small);
                grid.appendChild(label);
            };
            if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount,{once:true});else mount();
            new MutationObserver(mount).observe(document.body,{childList:true,subtree:true});
        })();
        </script><?php
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
        BCS_Release_083::initialize_profile($id, false);
        $r = self::registration($id);
        if (!$r || (string)($r->form_status ?? '') !== 'complete') {
            wp_send_json_error(['message'=>'Dane do faktury są dostępne po przesłaniu Formularza Obozowego.'], 409);
        }
        if (!empty($r->invoice_real_id)) {
            wp_send_json_error(['message'=>'Dane do faktury są zablokowane, ponieważ faktura została już wystawiona.'], 409);
        }

        $candidate = clone $r;
        $candidate->billing_type = sanitize_key(wp_unslash($_POST['billing_type'] ?? ''));
        $candidate->billing_name = sanitize_text_field(wp_unslash($_POST['billing_name'] ?? ''));
        $candidate->billing_street = sanitize_text_field(wp_unslash($_POST['billing_street'] ?? ''));
        $candidate->billing_postal_code = sanitize_text_field(wp_unslash($_POST['billing_postal_code'] ?? ''));
        $candidate->billing_city = sanitize_text_field(wp_unslash($_POST['billing_city'] ?? ''));
        $candidate->billing_nip = sanitize_text_field(wp_unslash($_POST['billing_nip'] ?? ''));
        $candidate->billing_notes = sanitize_textarea_field(wp_unslash($_POST['billing_notes'] ?? ''));
        $candidate->billing_ksef_description = self::clean_description(sanitize_textarea_field(wp_unslash($_POST['billing_ksef_description'] ?? '')));
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
            'billing_ksef_description'=>$candidate->billing_ksef_description,
            'billing_source'=>'admin_edit',
            'billing_updated_at'=>$now,
            'updated_at'=>$now,
        ];
        global $wpdb;
        $updated = $wpdb->update(BCS_DB::table('registrations'), $data, ['id'=>$id]);
        if ($updated === false) wp_send_json_error(['message'=>'Nie udało się zapisać danych do faktury.'], 500);
        BCS_Utils::log('invoice_profile_updated_084', [
            'billing_type'=>$data['billing_type'],
            'ksef_description_present'=>$data['billing_ksef_description'] !== '',
        ], $id, null);
        wp_send_json_success(['message'=>'Dane do faktury i dodatkowy opis KSeF zostały zapisane.']);
    }

    public static function card_description_ui(): void {
        if (!is_admin() || !current_user_can('manage_options')) return;
        if (sanitize_key(wp_unslash($_GET['page'] ?? '')) !== 'bcs-registrations') return;
        $id = absint($_GET['view'] ?? 0); if (!$id) return;
        BCS_Release_083::initialize_profile($id, false);
        $r = self::registration($id); if (!$r) return;
        $current = self::clean_description((string)($r->billing_ksef_description ?? ''));
        $submitted = self::clean_description((string)($r->invoice_ksef_description ?? ''));
        ?>
        <script>
        (()=>{
            const current=<?php echo wp_json_encode($current); ?>,submitted=<?php echo wp_json_encode($submitted); ?>;
            const mount=()=>{
                const panel=document.querySelector('[data-bcs-invoice-profile-083]');if(!panel)return;
                const view=panel.querySelector('.bcs-invoice-profile-view-083');
                if(view&&!view.querySelector('[data-bcs-ksef-description-view-084]')){
                    const row=document.createElement('div');row.className='is-wide';row.dataset.bcsKsefDescriptionView084='1';
                    const span=document.createElement('span');span.textContent='Dodatkowy opis do KSeF';const strong=document.createElement('strong');strong.textContent=current||'—';row.append(span,strong);view.appendChild(row);
                    if(submitted&&submitted!==current){const source=document.createElement('small');source.className='is-wide';source.style.display='block';source.style.marginTop='6px';source.textContent='W formularzu rodzic przesłał: '+submitted;row.appendChild(source);}
                }
                const form=panel.querySelector('[data-bcs-invoice-form-083]');
                if(form&&!form.elements.billing_ksef_description){
                    const notes=form.elements.billing_notes?.closest('label');const label=document.createElement('label');label.className='is-wide';
                    const span=document.createElement('span');span.textContent='Dodatkowy opis do KSeF';const area=document.createElement('textarea');area.name='billing_ksef_description';area.rows=3;area.maxLength=256;area.value=current;
                    const small=document.createElement('small');small.textContent='Trafi do Fa/DodatkowyOpis w KSeF. Np. imię i nazwisko uczestnika obozu. Maks. 256 znaków.';label.append(span,area,small);
                    if(notes)notes.insertAdjacentElement('afterend',label);else form.querySelector('div')?.appendChild(label);
                }
            };
            if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount,{once:true});else mount();
            window.setTimeout(mount,100);window.setTimeout(mount,500);new MutationObserver(mount).observe(document.body,{childList:true,subtree:true});
        })();
        </script><?php
    }

    private static function invoice(int $registrationId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT i.*, o.ksef_environment, o.ksef_anonymize_test '
            .'FROM '.BCS_DB::table('invoices').' i JOIN '.BCS_DB::table('organizers').' o ON o.id=i.organizer_id '
            .'WHERE i.registration_id=%d ORDER BY i.id DESC LIMIT 1',
            $registrationId
        )) ?: null;
    }

    /** Dodaje lub podmienia globalny Fa/DodatkowyOpis zgodnie z FA(3). */
    public static function inject_additional_description(string $xml, string $description): array {
        $description = self::clean_description($description);
        if ($description === '') return ['success'=>true,'xml'=>$xml,'description'=>''];
        if (!class_exists('DOMDocument')) return ['success'=>false,'xml'=>$xml,'message'=>'Brak rozszerzenia DOM wymaganego do przygotowania opisu KSeF.'];
        $dom = new DOMDocument(); $dom->formatOutput = true;
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors(); libxml_use_internal_errors($previous);
        if (!$loaded) return ['success'=>false,'xml'=>$xml,'message'=>'Nie udało się odczytać XML FA(3) przed dodaniem opisu.'];
        $ns = BCS_KSeF_Config::FA3_NAMESPACE;
        $xpath = new DOMXPath($dom); $xpath->registerNamespace('fa', $ns);
        $fa = $xpath->query('/fa:Faktura/fa:Fa')->item(0);
        if (!$fa instanceof DOMElement) return ['success'=>false,'xml'=>$xml,'message'=>'XML FA(3) nie zawiera elementu Fa.'];

        foreach ($xpath->query('/fa:Faktura/fa:Fa/fa:DodatkowyOpis') ?: [] as $existing) {
            $key = $xpath->query('fa:Klucz', $existing)->item(0);
            if ($key && trim((string)$key->textContent) === self::DESCRIPTION_KEY) $fa->removeChild($existing);
        }

        $extra = $dom->createElementNS($ns, 'DodatkowyOpis');
        $key = $dom->createElementNS($ns, 'Klucz'); $key->appendChild($dom->createTextNode(self::DESCRIPTION_KEY)); $extra->appendChild($key);
        $value = $dom->createElementNS($ns, 'Wartosc'); $value->appendChild($dom->createTextNode($description)); $extra->appendChild($value);

        $before = null;
        foreach (['FakturaZaliczkowa','FaWiersz','Rozliczenie','Platnosc','WarunkiTransakcji','Zamowienie'] as $name) {
            $candidate = $xpath->query('/fa:Faktura/fa:Fa/fa:'.$name)->item(0);
            if ($candidate) { $before = $candidate; break; }
        }
        if ($before) $fa->insertBefore($extra, $before); else $fa->appendChild($extra);
        return ['success'=>true,'xml'=>(string)$dom->saveXML(),'description'=>$description];
    }

    private static function prepare_xml_with_description(int $registrationId, object $invoice, string $description): array {
        global $wpdb;
        $prepared = BCS_KSeF_FA3::prepare_and_save((int)$invoice->id);
        if (empty($prepared['success'])) {
            $message = (string)($prepared['message'] ?? 'Nie udało się przygotować XML FA(3).');
            if (!empty($prepared['errors'])) $message .= ' '.implode(' ', array_map('strval', (array)$prepared['errors']));
            return ['success'=>false,'message'=>$message];
        }
        $fresh = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.BCS_DB::table('invoices').' WHERE id=%d', (int)$invoice->id));
        if (!$fresh) return ['success'=>false,'message'=>'Nie udało się ponownie odczytać faktury.'];
        $path = (string)($fresh->ksef_xml_path ?? '');
        $xml = $path !== '' && is_file($path) ? (string)file_get_contents($path) : '';
        if ($xml === '') return ['success'=>false,'message'=>'Przygotowany XML FA(3) jest pusty.'];

        $expected = json_decode((string)($fresh->buyer_snapshot ?? ''), true);
        if (!is_array($expected) || empty($expected['name'])) {
            $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.BCS_DB::table('registrations').' WHERE id=%d', $registrationId));
            $expected = $r ? BCS_Invoices::buyer_snapshot_from_registration($r) : [];
        }
        $actual = BCS_Release_082::buyer_from_xml($xml);
        if (!$expected || !BCS_Release_082::buyer_snapshots_match($expected, $actual)) {
            $message = 'Zablokowano wysyłkę do KSeF: dane nabywcy w XML różnią się od danych faktury.';
            $wpdb->update(BCS_DB::table('invoices'), [
                'ksef_status'=>'rejected','ksef_error_code'=>'BUYER_MISMATCH_084','ksef_error_message'=>$message,'ksef_last_checked_at'=>BCS_Utils::now(),
            ], ['id'=>(int)$invoice->id]);
            return ['success'=>false,'message'=>$message];
        }

        $injected = self::inject_additional_description($xml, $description);
        if (empty($injected['success'])) return $injected;
        $xml = (string)$injected['xml'];
        $validation = BCS_KSeF_FA3::validate($xml);
        if (empty($validation['success'])) {
            return ['success'=>false,'message'=>'XML FA(3) z dodatkowym opisem nie przeszedł walidacji: '.implode(' ', (array)$validation['errors'])];
        }
        if (file_put_contents($path, $xml, LOCK_EX) === false) return ['success'=>false,'message'=>'Nie udało się zapisać XML FA(3) z dodatkowym opisem.'];
        $wpdb->update(BCS_DB::table('invoices'), [
            'ksef_xml_hash'=>hash('sha256', $xml),
            'ksef_error_code'=>null,
            'ksef_error_message'=>null,
        ], ['id'=>(int)$invoice->id]);
        BCS_KSeF_FA3::operation((int)$invoice->id, (int)$invoice->organizer_id, 'Dodatkowy opis KSeF 0.84', 'success', null, [
            'description_present'=>trim((string)$injected['description']) !== '',
            'description_hash'=>hash('sha256', (string)$injected['description']),
        ]);
        return ['success'=>true,'xml'=>$xml,'path'=>$path];
    }

    /** Główna procedura 0.84: profil 0.83 + guard nabywcy 0.82 + DodatkowyOpis FA(3). */
    public static function generate_guarded(int $registrationId): array {
        BCS_Release_083::initialize_profile($registrationId, false);
        $r = self::registration($registrationId);
        if (!$r || empty($r->billing_initialized_at)) return ['success'=>false,'message'=>'Brak przygotowanych danych do faktury. Uzupełnij zakładkę „Dane do Faktury”.'];
        $valid = self::valid_profile($r);
        if ($valid['errors']) return ['success'=>false,'message'=>'Dane do faktury są niekompletne: '.implode(' ', $valid['errors'])];
        $description = self::clean_description((string)($r->billing_ksef_description ?? ''));

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
        if ($wpdb->update(BCS_DB::table('registrations'), $compat, ['id'=>$registrationId]) === false) {
            return ['success'=>false,'message'=>'Nie udało się przygotować danych nabywcy do wygenerowania faktury.'];
        }

        $restoreAnonymize = null; $organizerId = 0;
        try {
            $invoice = self::invoice($registrationId);
            if (!$invoice) {
                $path = BCS_Invoices::ensure_invoice($registrationId);
                if ($path === '' || !is_file($path)) return ['success'=>false,'message'=>'Nie udało się utworzyć dokumentu faktury.'];
                $invoice = self::invoice($registrationId);
            }
            if (!$invoice) return ['success'=>false,'message'=>'Nie udało się odczytać utworzonej faktury.'];
            if ((string)($invoice->ksef_status ?? '') === 'accepted' && !empty($invoice->ksef_number)) {
                return ['success'=>true,'message'=>'Faktura jest już przyjęta w KSeF.','status'=>'accepted','invoice_id'=>(int)$invoice->id,'ksef_number'=>(string)$invoice->ksef_number];
            }

            $organizerId = (int)$invoice->organizer_id;
            if (BCS_KSeF_Config::allowed_environment((string)($invoice->ksef_environment ?? 'test')) === 'test'
                && (int)($invoice->ksef_anonymize_test ?? 0) === 1) {
                $restoreAnonymize = 1;
                $wpdb->update(BCS_DB::table('organizers'), ['ksef_anonymize_test'=>0], ['id'=>$organizerId]);
            }

            $xml = self::prepare_xml_with_description($registrationId, $invoice, $description);
            if (empty($xml['success'])) return $xml;

            $sent = BCS_KSeF_Service::send((int)$invoice->id);
            if (empty($sent['success'])) return $sent;
            $status = (string)($sent['status'] ?? 'processing');
            if ($status === 'accepted') {
                BCS_KSeF_Invoice_Flow::finalize((int)$invoice->id);
                $final = BCS_KSeF_Invoice_Flow::last_result();
                $result = $final ?: $sent;
            } else {
                if (!wp_next_scheduled('bcs_ksef_finalize_invoice_076', [(int)$invoice->id])) {
                    wp_schedule_single_event(time() + 30, 'bcs_ksef_finalize_invoice_076', [(int)$invoice->id]);
                }
                $wpdb->update(BCS_DB::table('registrations'), ['invoice_status'=>'generated','updated_at'=>BCS_Utils::now()], ['id'=>$registrationId]);
                $result = $sent;
            }
            $result['success'] = true;
            $result['invoice_id'] = (int)$invoice->id;
            $result['invoice_number'] = (string)$invoice->invoice_number;
            BCS_Utils::log('invoice_generated_with_ksef_description_084', [
                'billing_type'=>$valid['type'],
                'description_present'=>$description !== '',
                'status'=>(string)($result['status'] ?? ''),
            ], $registrationId, null);
            return $result;
        } finally {
            if ($restoreAnonymize !== null && $organizerId > 0) {
                $wpdb->update(BCS_DB::table('organizers'), ['ksef_anonymize_test'=>$restoreAnonymize], ['id'=>$organizerId]);
            }
            $wpdb->update(BCS_DB::table('registrations'), $original, ['id'=>$registrationId]);
        }
    }

    private static function json_result(array $result): void {
        !empty($result['success']) ? wp_send_json_success($result) : wp_send_json_error($result, 422);
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
        if(!$valid)wp_send_json_error(['message'=>'Sesja wygasła.'],403);self::json_result(self::generate_guarded($id));
    }

    public static function ajax_legacy_generate(): void {
        if(!current_user_can('manage_options'))wp_send_json_error(['message'=>'Brak uprawnień.'],403);check_ajax_referer('bcs_generate_invoice_0200','nonce');$id=absint($_POST['registration_id']??0);if(!$id)wp_send_json_error(['message'=>'Nieprawidłowe zgłoszenie.'],422);self::json_result(self::generate_guarded($id));
    }

    public static function classic_generate(): void {
        if(!is_admin()||!current_user_can('manage_options'))return;
        if(!empty($_POST['bcs_crm_action'])&&sanitize_key(wp_unslash($_POST['bcs_crm_action']))==='invoice_generate'){$id=absint($_POST['registration_id']??0);check_admin_referer('bcs_crm_'.$id);$result=self::generate_guarded($id);set_transient('bcs_ksef_invoice_result_'.get_current_user_id().'_'.$id,$result,5*MINUTE_IN_SECONDS);wp_safe_redirect(add_query_arg(['page'=>'bcs-registrations','view'=>$id,'crm_done'=>!empty($result['success'])?1:0],admin_url('admin.php')));exit;}
        if(!empty($_POST['bcs_workflow_action'])&&sanitize_key(wp_unslash($_POST['bcs_workflow_action']))==='generate_invoice'){check_admin_referer('bcs_workflow_action');$ids=array_values(array_filter(array_map('absint',(array)($_POST['registration_ids']??[]))));$ok=0;$failed=0;foreach($ids as $id)!empty(self::generate_guarded($id)['success'])?$ok++:$failed++;wp_safe_redirect(add_query_arg(['page'=>'bcs-registrations','done'=>$ok,'failed'=>$failed],admin_url('admin.php')));exit;}
    }

    public static function single_generate(): void {
        if(!current_user_can('manage_options'))return;$action=sanitize_key(wp_unslash($_GET['workflow']??''));if($action!=='generate_invoice')return;$id=absint($_GET['registration_id']??0);check_admin_referer('bcs_workflow_single_'.$id.'_generate_invoice');$result=self::generate_guarded($id);wp_safe_redirect(add_query_arg(['page'=>'bcs-registrations','view'=>$id,'done'=>!empty($result['success'])?1:0,'failed'=>empty($result['success'])?1:0],admin_url('admin.php')));exit;
    }
}
