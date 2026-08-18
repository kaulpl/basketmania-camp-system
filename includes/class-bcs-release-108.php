<?php
if (!defined('ABSPATH')) exit;

/**
 * 1.08 – archiwizacja zakończonych turnusów i trwałe dowody KSeF.
 */
final class BCS_Release_108 {
    private const ARCHIVES_TABLE = 'camp_archives';
    private const ARCHIVE_ACTION = 'bcs_archive_camp_108';
    private const DOWNLOAD_ACTION = 'bcs_download_camp_archive_108';
    private const EVIDENCE_HOOK = 'bcs_ksef_evidence_backfill_108';
    private const SCHEMA_OPTION = 'bcs_release_108_schema';

    public static function init(): void {
        self::ensure_schema();
        add_action('admin_init', [__CLASS__, 'guard_archived_mutations'], 0);
        add_action('admin_menu', [__CLASS__, 'replace_camps_page'], 1000);
        add_action('admin_post_'.self::ARCHIVE_ACTION, [__CLASS__, 'handle_archive']);
        add_action('admin_post_'.self::DOWNLOAD_ACTION, [__CLASS__, 'handle_download']);
        add_action('bcs_ksef_finalize_invoice_076', [__CLASS__, 'after_ksef_finalize'], 99, 1);
        add_action(self::EVIDENCE_HOOK, [__CLASS__, 'backfill_ksef_evidence']);
        add_action('init', [__CLASS__, 'ensure_evidence_schedule']);
    }

    public static function archives_table(): string { return BCS_DB::table(self::ARCHIVES_TABLE); }

    public static function ensure_schema(): void {
        if ((string)get_option(self::SCHEMA_OPTION, '') === '1.08') return;
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $camps = BCS_DB::table('camps');
        $columns = [
            'archived_at' => 'DATETIME NULL',
            'archived_by' => 'BIGINT UNSIGNED NULL',
            'archive_status' => "VARCHAR(30) NULL",
            'archive_latest_id' => 'BIGINT UNSIGNED NULL',
        ];
        foreach ($columns as $column=>$definition) {
            $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$camps} LIKE %s", $column));
            if ($exists === null) $wpdb->query("ALTER TABLE {$camps} ADD COLUMN {$column} {$definition}");
        }
        $charset = $wpdb->get_charset_collate();
        $archives = self::archives_table();
        dbDelta("CREATE TABLE {$archives} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            camp_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'complete',
            file_path TEXT NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            sha256 CHAR(64) NOT NULL,
            manifest_json LONGTEXT NULL,
            warnings_json LONGTEXT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY camp_id (camp_id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset};");
        update_option(self::SCHEMA_OPTION, '1.08', false);
    }

    public static function ensure_evidence_schedule(): void {
        if (!wp_next_scheduled(self::EVIDENCE_HOOK)) wp_schedule_event(time()+300, 'hourly', self::EVIDENCE_HOOK);
    }

    public static function after_ksef_finalize(int $invoiceId): void {
        self::ensure_invoice_ksef_evidence($invoiceId, false);
    }

    public static function backfill_ksef_evidence(): void {
        if (!class_exists('BCS_KSeF_Service')) return;
        global $wpdb;
        $table = BCS_DB::table('invoices');
        $ids = $wpdb->get_col("SELECT id FROM {$table}
            WHERE ksef_status='accepted'
              AND (ksef_remote_xml_path IS NULL OR ksef_remote_xml_path='' OR ksef_upo_path IS NULL OR ksef_upo_path='')
            ORDER BY id ASC LIMIT 5");
        foreach ((array)$ids as $id) self::ensure_invoice_ksef_evidence((int)$id, false);
    }

    /** @return array{warnings:array, invoice:?object} */
    private static function ensure_invoice_ksef_evidence(int $invoiceId, bool $archiveContext): array {
        global $wpdb;
        $warnings = [];
        $table = BCS_DB::table('invoices');
        $invoice = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $invoiceId));
        if (!$invoice || (string)$invoice->ksef_status !== 'accepted') return ['warnings'=>$warnings, 'invoice'=>$invoice];
        if (!class_exists('BCS_KSeF_Service')) {
            $warnings[] = 'Moduł KSeF Service jest niedostępny.';
            return ['warnings'=>$warnings, 'invoice'=>$invoice];
        }

        if (empty($invoice->ksef_upo_path) || !is_file((string)$invoice->ksef_upo_path)) {
            $upo = self::try_fetch_upo_without_status_downgrade($invoiceId);
            if (!$upo['success'] && $archiveContext && !empty($upo['message'])) {
                $warnings[] = 'Nie udało się uzupełnić UPO KSeF dla faktury #'.$invoiceId.': '.(string)$upo['message'];
            }
        }
        $invoice = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $invoiceId));
        if ($invoice && (empty($invoice->ksef_remote_xml_path) || !is_file((string)$invoice->ksef_remote_xml_path))) {
            $remote = BCS_KSeF_Service::fetch_remote_xml($invoiceId);
            if (empty($remote['success']) && $archiveContext) {
                $warnings[] = 'Nie udało się pobrać źródłowego XML z KSeF dla faktury #'.$invoiceId.': '.(string)($remote['message'] ?? 'nieznany błąd');
            }
        }
        $invoice = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $invoiceId));
        if ($archiveContext && $invoice) {
            if (empty($invoice->ksef_xml_path) || !is_file((string)$invoice->ksef_xml_path)) $warnings[] = 'Brak lokalnego XML FA(3) dla faktury #'.$invoiceId.'.';
            if (empty($invoice->ksef_remote_xml_path) || !is_file((string)$invoice->ksef_remote_xml_path)) $warnings[] = 'Brak XML pobranego z KSeF dla faktury #'.$invoiceId.'.';
            if (empty($invoice->ksef_upo_path) || !is_file((string)$invoice->ksef_upo_path)) $warnings[] = 'Brak pliku UPO dla faktury #'.$invoiceId.'.';
            if (empty($invoice->ksef_number)) $warnings[] = 'Brak numeru KSeF dla faktury #'.$invoiceId.'.';
        }
        return ['warnings'=>$warnings, 'invoice'=>$invoice];
    }

    /** Próbuje pobrać UPO bez zmiany statusu wcześniej zaakceptowanej faktury w razie błędu sieciowego. */
    private static function try_fetch_upo_without_status_downgrade(int $invoiceId): array {
        global $wpdb;
        if (!class_exists('BCS_KSeF_Auth') || !class_exists('BCS_KSeF_Client')) return ['success'=>false,'message'=>'Moduł klienta KSeF jest niedostępny.'];
        $invoice = $wpdb->get_row($wpdb->prepare(
            'SELECT i.*,o.ksef_environment,o.ksef_context_nip,o.ksef_token_ciphertext,o.ksef_token_nonce,o.ksef_production_token_ciphertext,o.ksef_production_token_nonce '
            .'FROM '.BCS_DB::table('invoices').' i JOIN '.BCS_DB::table('organizers').' o ON o.id=i.organizer_id WHERE i.id=%d',
            $invoiceId
        ));
        if (!$invoice || (string)$invoice->ksef_status !== 'accepted') return ['success'=>false,'message'=>'Faktura nie jest zaakceptowana w KSeF.'];
        if (empty($invoice->ksef_session_reference) || empty($invoice->ksef_invoice_reference)) return ['success'=>false,'message'=>'Brak referencji sesji lub faktury KSeF.'];
        $environment = BCS_KSeF_Config::allowed_environment((string)($invoice->ksef_environment_used ?: $invoice->ksef_environment ?: 'test'));
        $auth = BCS_KSeF_Auth::authenticate($invoice, $environment);
        if (empty($auth['success'])) return ['success'=>false,'message'=>'Nie udało się uwierzytelnić w KSeF.'];
        $response = $auth['client']->session_invoice_status((string)$invoice->ksef_session_reference, (string)$invoice->ksef_invoice_reference, (string)$auth['access_token']);
        if (empty($response['success'])) return ['success'=>false,'message'=>(string)($response['message'] ?? 'Nie udało się pobrać statusu faktury.')];
        $url = (string)($response['data']['upoDownloadUrl'] ?? '');
        if ($url === '') return ['success'=>false,'message'=>'KSeF nie zwrócił adresu UPO w aktualnej odpowiedzi statusowej.'];
        $client = new BCS_KSeF_Client($environment);
        $download = $client->download_url($url);
        if (empty($download['success']) || trim((string)($download['raw'] ?? '')) === '') return ['success'=>false,'message'=>'Nie udało się pobrać pliku UPO.'];
        $directory = BCS_Document_Engine::uploads_dir().'/registration-'.(int)$invoice->registration_id;
        if (!is_dir($directory)) wp_mkdir_p($directory);
        $path = $directory.'/06-ksef-upo-'.(int)$invoice->id.'.xml';
        if (file_put_contents($path, (string)$download['raw'], LOCK_EX) === false) return ['success'=>false,'message'=>'Nie udało się zapisać pliku UPO.'];
        $wpdb->update(BCS_DB::table('invoices'), ['ksef_upo_path'=>$path,'ksef_last_checked_at'=>BCS_Utils::now()], ['id'=>$invoiceId]);
        return ['success'=>true,'message'=>'UPO zapisane.','path'=>$path];
    }

    /** Blokuje operacyjne modyfikacje danych turnusu po archiwizacji. */
    public static function guard_archived_mutations(): void {
        $action = sanitize_key((string)($_REQUEST['action'] ?? ''));
        if (in_array($action, [self::ARCHIVE_ACTION,self::DOWNLOAD_ACTION,'bcs_download_document','bcs_download_package'], true)) return;
        $markers = [
            'bcs_save_registration','bcs_delete_registration','bcs_crm_action','bcs_workflow_action','quick_action','card_action',
            'bcs_save_camp','bcs_delete_camp','bcs_mail_reply','bcs_mail_assign','bcs_mail_create_registration',
        ];
        $mutation = false;
        foreach ($markers as $field) if (!empty($_REQUEST[$field])) { $mutation = true; break; }
        if (!$mutation && $action !== '') {
            $mutation = (bool)preg_match('/^bcs_.*(?:save|delete|send|generate|mark|verify|accept|cancel|reply|assign|create|update|import|launch|pause|resume|complete)/', $action);
        }
        if (!$mutation) return;
        $campId = self::camp_id_from_request($_REQUEST);
        if (!$campId || !self::camp_is_archived($campId)) return;
        $message = 'Ten turnus jest zarchiwizowany. Dane historyczne są tylko do odczytu.';
        if (wp_doing_ajax()) wp_send_json_error(['message'=>$message], 409);
        wp_die(esc_html($message), 'Turnus zarchiwizowany', ['response'=>409,'back_link'=>true]);
    }

    private static function camp_id_from_request(array $request): int {
        global $wpdb;
        if (!empty($request['camp_id'])) return absint($request['camp_id']);
        if (!empty($request['registration_id'])) return (int)$wpdb->get_var($wpdb->prepare('SELECT camp_id FROM '.BCS_DB::table('registrations').' WHERE id=%d', absint($request['registration_id'])));
        if (!empty($request['agreement_id'])) return (int)$wpdb->get_var($wpdb->prepare('SELECT r.camp_id FROM '.BCS_DB::table('agreements').' a JOIN '.BCS_DB::table('registrations').' r ON r.id=a.registration_id WHERE a.id=%d', absint($request['agreement_id'])));
        if (!empty($request['invoice_id'])) return (int)$wpdb->get_var($wpdb->prepare('SELECT r.camp_id FROM '.BCS_DB::table('invoices').' i JOIN '.BCS_DB::table('registrations').' r ON r.id=i.registration_id WHERE i.id=%d', absint($request['invoice_id'])));
        if (!empty($request['payment_id'])) return (int)$wpdb->get_var($wpdb->prepare('SELECT r.camp_id FROM '.BCS_DB::table('payments').' p JOIN '.BCS_DB::table('registrations').' r ON r.id=p.registration_id WHERE p.id=%d', absint($request['payment_id'])));
        if (!empty($request['message_id'])) return (int)$wpdb->get_var($wpdb->prepare('SELECT r.camp_id FROM '.BCS_DB::table('mail_messages').' m JOIN '.BCS_DB::table('registrations').' r ON r.id=m.registration_id WHERE m.id=%d', absint($request['message_id'])));
        return 0;
    }

    private static function camp_is_archived(int $campId): bool {
        global $wpdb;
        return (string)$wpdb->get_var($wpdb->prepare('SELECT status FROM '.BCS_DB::table('camps').' WHERE id=%d', $campId)) === 'archived';
    }

    public static function replace_camps_page(): void {
        if (!function_exists('get_plugin_page_hookname')) return;
        $hook = get_plugin_page_hookname('bcs-camps', 'bcs-dashboard');
        remove_action($hook, ['BCS_Admin', 'camps']);
        add_action($hook, [__CLASS__, 'camps_page']);
    }

    private static function status_label(string $status): string {
        return ['open'=>'Otwarte','draft'=>'Szkic','closed'=>'Zamknięte','archived'=>'Zarchiwizowane'][$status] ?? 'Nieznany';
    }

    private static function archive_status_label(string $status): string {
        return ['complete'=>'Archiwum kompletne','requires_attention'=>'Archiwum wymaga uwagi','failed'=>'Błąd archiwizacji'][$status] ?? 'Brak archiwum';
    }

    public static function camps_page(): void {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $view = sanitize_key(wp_unslash($_GET['view_mode'] ?? 'active'));
        if (!in_array($view, ['active','archived','all'], true)) $view = 'active';
        $where = $view === 'active' ? "WHERE c.status<>'archived'" : ($view === 'archived' ? "WHERE c.status='archived'" : '');
        $archives = self::archives_table();
        $rows = $wpdb->get_results("SELECT c.*,o.name organizer_name,COUNT(r.id) registrations,
            (SELECT a.id FROM {$archives} a WHERE a.camp_id=c.id ORDER BY a.id DESC LIMIT 1) archive_id,
            (SELECT a.status FROM {$archives} a WHERE a.camp_id=c.id ORDER BY a.id DESC LIMIT 1) archive_record_status,
            (SELECT a.created_at FROM {$archives} a WHERE a.camp_id=c.id ORDER BY a.id DESC LIMIT 1) archive_created_at,
            (SELECT a.file_size FROM {$archives} a WHERE a.camp_id=c.id ORDER BY a.id DESC LIMIT 1) archive_file_size
            FROM ".BCS_DB::table('camps')." c
            LEFT JOIN ".BCS_DB::table('organizers')." o ON o.id=c.organizer_id
            LEFT JOIN ".BCS_DB::table('registrations')." r ON r.camp_id=c.id AND r.status<>'cancelled'
            {$where} GROUP BY c.id ORDER BY c.start_date DESC, c.id DESC");
        $organizers = $wpdb->get_results("SELECT * FROM ".BCS_DB::table('organizers')." ORDER BY name");
        $editId = absint($_GET['edit'] ?? 0);
        $edit = $editId ? $wpdb->get_row($wpdb->prepare("SELECT * FROM ".BCS_DB::table('camps')." WHERE id=%d", $editId)) : null;
        self::notice();
        echo '<div class="wrap bcs-admin"><div class="bcs-page-head"><div><h1>Turnusy</h1><p>Terminy, limity, ceny, organizatorzy, dokumenty i archiwizacja zakończonych turnusów.</p></div><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=bcs-camps')).'">Dodaj turnus</a></div>';
        echo '<nav class="bcs-mail-nav" style="margin-bottom:20px"><a class="'.($view==='active'?'is-active':'').'" href="'.esc_url(admin_url('admin.php?page=bcs-camps&view_mode=active')).'">Aktywne</a><a class="'.($view==='archived'?'is-active':'').'" href="'.esc_url(admin_url('admin.php?page=bcs-camps&view_mode=archived')).'">Zarchiwizowane</a><a class="'.($view==='all'?'is-active':'').'" href="'.esc_url(admin_url('admin.php?page=bcs-camps&view_mode=all')).'">Wszystkie</a></nav>';
        echo '<div class="bcs-list-grid">';
        foreach ((array)$rows as $r) self::camp_card($r);
        if (!$rows) echo '<div class="bcs-empty">Brak turnusów w wybranym widoku.</div>';
        echo '</div>';
        if ($edit && (string)$edit->status === 'archived') self::archived_camp_panel($edit);
        else self::camp_form($edit, $organizers);
        echo '</div>';
    }

    private static function camp_card(object $r): void {
        $fill = (int)$r->capacity ? min(100, round(((int)$r->registrations/(int)$r->capacity)*100)) : 0;
        $archived = (string)$r->status === 'archived';
        echo '<article class="bcs-list-card'.($archived?' bcs-camp-archived-108':'').'"><div class="bcs-card-top"><div><div class="bcs-card-labels"><span class="bcs-badge status-'.esc_attr((string)$r->status).'">'.esc_html(self::status_label((string)$r->status)).'</span><span class="bcs-id">#'.(int)$r->id.'</span></div><h2>'.esc_html((string)$r->name).'</h2><p>'.esc_html((string)$r->start_date.' – '.(string)$r->end_date).' · '.esc_html((string)($r->location ?: '—')).'</p></div><strong class="bcs-count">'.(int)$r->registrations.'</strong></div>';
        echo '<div class="bcs-progress"><span style="width:'.esc_attr((string)$fill).'%"></span></div><dl><div><dt>Cena</dt><dd>'.number_format((float)$r->price,2,',',' ').' zł</dd></div><div><dt>Limit</dt><dd>'.(int)$r->capacity.'</dd></div><div><dt>Organizator</dt><dd>'.esc_html((string)($r->organizer_name ?: '—')).'</dd></div></dl>';
        if ($archived) echo '<div class="bcs-meta-list" style="margin-top:12px"><p><strong>Archiwizacja:</strong> '.esc_html(self::archive_status_label((string)($r->archive_record_status ?: $r->archive_status))).'</p><p><strong>Data:</strong> '.esc_html($r->archive_created_at ? BCS_Utils::format_datetime((string)$r->archive_created_at) : '—').'</p></div>';
        echo '<div class="bcs-card-actions">';
        if (!$archived) echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=bcs-camps&edit='.(int)$r->id)).'">Edytuj</a>';
        else echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=bcs-camps&edit='.(int)$r->id.'&view_mode=archived')).'">Szczegóły</a>';
        echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=bcs-registrations&camp_id='.(int)$r->id)).'">Zgłoszenia</a>';
        if (!empty($r->archive_id)) echo '<a class="button" href="'.esc_url(self::download_url((int)$r->archive_id)).'">Pobierz archiwum</a>';
        echo self::archive_form((int)$r->id, $archived ? 'Utwórz nową paczkę' : 'Zamknij i zarchiwizuj', $archived);
        echo '</div></article>';
    }

    private static function archive_form(int $campId, string $label, bool $regenerate=false): string {
        ob_start();
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="bcs-inline-delete" data-confirm="'.esc_attr($regenerate ? 'Utworzyć nową wersję paczki archiwalnej dla tego turnusu?' : 'Zamknąć turnus i utworzyć pełne archiwum? Po archiwizacji turnus będzie tylko do odczytu.').'">';
        echo '<input type="hidden" name="action" value="'.esc_attr(self::ARCHIVE_ACTION).'"><input type="hidden" name="camp_id" value="'.$campId.'"><input type="hidden" name="archive_confirm" value="1">';
        wp_nonce_field(self::ARCHIVE_ACTION.'_'.$campId);
        echo '<button class="button'.($regenerate?'':' button-primary').'">'.esc_html($label).'</button></form>';
        return (string)ob_get_clean();
    }

    private static function archived_camp_panel(object $camp): void {
        global $wpdb;
        $archives = $wpdb->get_results($wpdb->prepare("SELECT * FROM ".self::archives_table()." WHERE camp_id=%d ORDER BY id DESC", (int)$camp->id));
        echo '<section class="bcs-panel"><div class="bcs-panel-head"><div><h2>Zarchiwizowany turnus #'.(int)$camp->id.'</h2><p>Dane turnusu pozostają w systemie w trybie historycznym.</p></div><span class="bcs-badge status-archived">Zarchiwizowane</span></div>';
        echo '<div class="bcs-meta-list"><p><strong>Nazwa:</strong> '.esc_html((string)$camp->name).'</p><p><strong>Termin:</strong> '.esc_html((string)$camp->start_date.' – '.(string)$camp->end_date).'</p><p><strong>Zarchiwizowano:</strong> '.esc_html($camp->archived_at ? BCS_Utils::format_datetime((string)$camp->archived_at) : '—').'</p></div>';
        echo '<h3>Wersje archiwum</h3><div class="bcs-table-wrap"><table class="widefat striped"><thead><tr><th>Data</th><th>Status</th><th>Rozmiar</th><th>SHA-256</th><th></th></tr></thead><tbody>';
        foreach ((array)$archives as $a) echo '<tr><td>'.esc_html(BCS_Utils::format_datetime((string)$a->created_at)).'</td><td>'.esc_html(self::archive_status_label((string)$a->status)).'</td><td>'.esc_html(size_format((int)$a->file_size)).'</td><td><code>'.esc_html((string)$a->sha256).'</code></td><td><a class="button" href="'.esc_url(self::download_url((int)$a->id)).'">Pobierz ZIP</a></td></tr>';
        if (!$archives) echo '<tr><td colspan="5">Brak zapisanej paczki archiwalnej.</td></tr>';
        echo '</tbody></table></div><div class="bcs-form-actions">'.self::archive_form((int)$camp->id, 'Utwórz nową paczkę archiwalną', true).'</div></section>';
    }

    private static function camp_form(?object $edit, array $organizers): void {
        $id = (int)($edit->id ?? 0);
        $v = static fn(string $field, string $default=''): string => esc_attr((string)($edit->{$field} ?? $default));
        echo '<section class="bcs-panel"><div class="bcs-panel-head"><h2>'.($edit?'Edytuj turnus #'.$id:'Dodaj turnus').'</h2>'.($edit?'<a class="button" href="'.esc_url(admin_url('admin.php?page=bcs-camps')).'">Anuluj edycję</a>':'').'</div><form method="post">';
        wp_nonce_field('bcs_save_camp');
        echo '<input type="hidden" name="camp_id" value="'.$id.'"><div class="bcs-form-grid">';
        foreach ([['name','Nazwa','text'],['slug','Slug','text'],['start_date','Data od','date'],['end_date','Data do','date'],['location','Miejsce','text'],['price','Cena','number'],['capacity','Limit miejsc','number']] as $f) echo '<label><span>'.esc_html($f[1]).'</span><input type="'.esc_attr($f[2]).'" '.($f[2]==='number'?'step="0.01" min="0"':'').' name="'.esc_attr($f[0]).'" value="'.$v($f[0]).'" required></label>';
        echo '<label><span>Organizator</span><select name="organizer_id" required><option value="">— wybierz —</option>';
        foreach ((array)$organizers as $o) echo '<option value="'.(int)$o->id.'" '.selected((int)($edit->organizer_id??0),(int)$o->id,false).'>'.esc_html((string)$o->name).'</option>';
        echo '</select></label><label><span>Status</span><select name="status">';
        foreach (['open'=>'Otwarte','draft'=>'Szkic','closed'=>'Zamknięte'] as $key=>$label) echo '<option value="'.$key.'" '.selected((string)($edit->status??'draft'),$key,false).'>'.$label.'</option>';
        echo '</select></label></div><p class="description bcs-span-2">Status „Zarchiwizowane” jest nadawany wyłącznie przez akcję „Zamknij i zarchiwizuj”, która tworzy pełną paczkę danych.</p><div class="bcs-form-actions"><button class="button button-primary button-hero" name="bcs_save_camp" value="1">'.($edit?'Zapisz zmiany':'Dodaj turnus').'</button></div></form></section>';
    }

    private static function notice(): void {
        if (!empty($_GET['archived'])) echo '<div class="notice notice-success is-dismissible"><p>Turnus został zarchiwizowany, a paczka archiwalna została utworzona.</p></div>';
        if (!empty($_GET['archive_warning'])) echo '<div class="notice notice-warning is-dismissible"><p>Archiwum utworzono, ale wymaga uwagi. Sprawdź manifest i listę brakujących elementów w paczce.</p></div>';
        $error = sanitize_key(wp_unslash($_GET['archive_error'] ?? ''));
        $messages = ['not_found'=>'Nie znaleziono turnusu.','zip'=>'Serwer nie obsługuje tworzenia plików ZIP.','busy'=>'Archiwizacja tego turnusu jest już w toku.','create'=>'Nie udało się utworzyć paczki archiwalnej.'];
        if ($error && isset($messages[$error])) echo '<div class="notice notice-error is-dismissible"><p>'.esc_html($messages[$error]).'</p></div>';
    }

    public static function handle_archive(): void {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        @set_time_limit(300);
        $campId = absint($_POST['camp_id'] ?? 0);
        check_admin_referer(self::ARCHIVE_ACTION.'_'.$campId);
        if (!$campId || empty($_POST['archive_confirm'])) self::redirect_archive_error('not_found');
        $lock = 'bcs_archive_108_'.$campId;
        if (get_transient($lock)) self::redirect_archive_error('busy');
        set_transient($lock, 1, 15*MINUTE_IN_SECONDS);
        try { $result = self::create_archive($campId); }
        catch (Throwable $e) {
            delete_transient($lock);
            BCS_Utils::log('audit_camp_archive_failed', ['module'=>'Turnusy i archiwum','message'=>$e->getMessage()], null, null);
            self::redirect_archive_error('create'); return;
        }
        delete_transient($lock);
        if (!$result['success']) self::redirect_archive_error((string)($result['error'] ?? 'create'));
        $args = ['page'=>'bcs-camps','view_mode'=>'archived','edit'=>$campId,'archived'=>1];
        if (($result['status'] ?? '') === 'requires_attention') $args['archive_warning'] = 1;
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php'))); exit;
    }

    private static function redirect_archive_error(string $error): void {
        wp_safe_redirect(add_query_arg(['page'=>'bcs-camps','archive_error'=>$error], admin_url('admin.php'))); exit;
    }

    /** @return array{success:bool,status?:string,error?:string,archive_id?:int} */
    public static function create_archive(int $campId): array {
        if (!class_exists('ZipArchive')) return ['success'=>false,'error'=>'zip'];
        global $wpdb;
        $camp = $wpdb->get_row($wpdb->prepare("SELECT c.*,o.name organizer_name,o.id organizer_real_id FROM ".BCS_DB::table('camps')." c LEFT JOIN ".BCS_DB::table('organizers')." o ON o.id=c.organizer_id WHERE c.id=%d", $campId));
        if (!$camp) return ['success'=>false,'error'=>'not_found'];
        $warnings = array_values(array_unique(array_merge(self::completeness_warnings($campId), self::prepare_ksef_evidence_for_camp($campId))));
        $status = $warnings ? 'requires_attention' : 'complete';
        $directory = self::archive_dir();
        $year = preg_match('/^(\d{4})-/', (string)$camp->start_date, $m) ? $m[1] : BCS_Utils::today('Y');
        $slug = sanitize_file_name((string)($camp->slug ?: sanitize_title((string)$camp->name)));
        $filename = 'basketmania-camp-'.$year.'-'.$slug.'-archiwum-'.BCS_Utils::today('Ymd-His').'.zip';
        $path = $directory.'/'.$filename;
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE|ZipArchive::OVERWRITE) !== true) return ['success'=>false,'error'=>'create'];
        $hashes = [];
        $addString = static function(string $name, string $content) use ($zip, &$hashes): void { $zip->addFromString($name, $content); $hashes[$name] = hash('sha256', $content); };
        $addFile = static function(string $name, string $file) use ($zip, &$hashes): bool { if ($file === '' || !is_file($file)) return false; $zip->addFile($file, $name); $hashes[$name] = hash_file('sha256', $file) ?: ''; return true; };
        $registrations = $wpdb->get_results($wpdb->prepare("SELECT * FROM ".BCS_DB::table('registrations')." WHERE camp_id=%d ORDER BY id", $campId));
        $invoiceRows = $wpdb->get_results($wpdb->prepare("SELECT i.* FROM ".BCS_DB::table('invoices')." i JOIN ".BCS_DB::table('registrations')." r ON r.id=i.registration_id WHERE r.camp_id=%d ORDER BY i.id", $campId));
        $addString('README.txt', self::readme($camp, $status, $warnings));
        $addString('dane/uczestnicy.csv', self::participants_csv($registrations, false));
        $addString('dane-wrazliwe/uczestnicy-dane-wrazliwe.csv', self::participants_csv($registrations, true));
        $addString('dane/rozliczenia.csv', self::finances_csv($registrations, $invoiceRows));
        $addString('dane/ksef.csv', self::ksef_csv($invoiceRows));
        $addString('dane/logi.csv', self::logs_csv($campId));
        $addString('database/turnus-'.$campId.'.sql', self::sql_dump($camp));
        $addString('database/struktura-bazy.sql', self::schema_dump());
        $firstRegistrationId = 0;
        foreach ((array)$registrations as $r) {
            $rid = (int)$r->id; if (!$firstRegistrationId) $firstRegistrationId = $rid;
            $base = 'dokumenty/zgloszenie-'.$rid.'/';
            $addFile($base.'01-formularz-zgloszeniowy.pdf', BCS_Document_Engine::form_pdf($rid));
            if ((string)$r->agreement_status === 'accepted' && !$addFile($base.'02-umowa-podpisana.pdf', BCS_Document_Engine::agreement_pdf($rid, 'signed'))) $warnings[] = 'Nie udało się dołączyć podpisanej umowy dla zgłoszenia #'.$rid.'.';
            $mail = self::correspondence_json($rid); if ($mail !== '') $addString('korespondencja/zgloszenie-'.$rid.'.json', $mail);
        }
        if ($firstRegistrationId) $addFile('dokumenty/03-regulamin-obozu.pdf', BCS_Document_Engine::regulations_pdf($firstRegistrationId));
        foreach ((array)$invoiceRows as $invoice) {
            $base = 'faktury/faktura-'.(int)$invoice->id.'/';
            if (!empty($invoice->file_path)) $addFile($base.'faktura.pdf', (string)$invoice->file_path);
            if (!empty($invoice->ksef_xml_path)) $addFile($base.'fa3-wyslany.xml', (string)$invoice->ksef_xml_path);
            if (!empty($invoice->ksef_remote_xml_path)) $addFile($base.'xml-pobrany-z-ksef.xml', (string)$invoice->ksef_remote_xml_path);
            if (!empty($invoice->ksef_upo_path)) $addFile($base.'upo.xml', (string)$invoice->ksef_upo_path);
            $addString($base.'potwierdzenie-ksef.json', wp_json_encode(self::ksef_proof_array($invoice), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        }
        $warnings = array_values(array_unique($warnings)); $status = $warnings ? 'requires_attention' : 'complete';
        $manifest = ['archive_format'=>'Basketmania Camp Archive 1.0','system_version'=>defined('BCS_VERSION')?BCS_VERSION:'1.08','created_at'=>BCS_Utils::now(),'created_by'=>get_current_user_id(),'camp'=>['id'=>(int)$camp->id,'name'=>(string)$camp->name,'slug'=>(string)$camp->slug,'start_date'=>(string)$camp->start_date,'end_date'=>(string)$camp->end_date,'organizer_id'=>(int)$camp->organizer_id,'organizer_name'=>(string)$camp->organizer_name],'status'=>$status,'warnings'=>$warnings,'counts'=>['registrations'=>count((array)$registrations),'invoices'=>count((array)$invoiceRows),'files_before_manifest'=>count($hashes)],'database_dump'=>'database/turnus-'.$campId.'.sql','database_schema'=>'database/struktura-bazy.sql','security_note'=>'Archiwum zawiera dane osobowe i może zawierać dane szczególnej kategorii. Przechowywać wyłącznie w zabezpieczonym miejscu.'];
        $manifestJson = wp_json_encode($manifest, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $addString('manifest.json', $manifestJson); ksort($hashes); $sums=''; foreach($hashes as $name=>$hash) $sums.=$hash.'  '.$name."\n"; $addString('SHA256SUMS.txt',$sums); $zip->close();
        $sha = hash_file('sha256',$path) ?: ''; $size = filesize($path) ?: 0; $now = BCS_Utils::now();
        $wpdb->insert(self::archives_table(), ['camp_id'=>$campId,'status'=>$status,'file_path'=>$path,'file_name'=>$filename,'file_size'=>$size,'sha256'=>$sha,'manifest_json'=>$manifestJson,'warnings_json'=>wp_json_encode($warnings,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'created_by'=>get_current_user_id()?:null,'created_at'=>$now]);
        $archiveId=(int)$wpdb->insert_id; if(!$archiveId){@unlink($path);return ['success'=>false,'error'=>'create'];}
        $wpdb->update(BCS_DB::table('camps'), ['status'=>'archived','archived_at'=>$now,'archived_by'=>get_current_user_id()?:null,'archive_status'=>$status,'archive_latest_id'=>$archiveId,'updated_at'=>$now], ['id'=>$campId]);
        BCS_Utils::log('audit_camp_archive',['module'=>'Turnusy i archiwum','camp_id'=>$campId,'archive_id'=>$archiveId,'status'=>$status,'file_size'=>$size,'sha256'=>$sha,'warnings_count'=>count($warnings)],null,null);
        return ['success'=>true,'status'=>$status,'archive_id'=>$archiveId];
    }

    private static function archive_dir(): string {
        $uploads=wp_upload_dir(); $dir=trailingslashit((string)$uploads['basedir']).'basketmania-archives'; if(!is_dir($dir)) wp_mkdir_p($dir);
        if(!is_file($dir.'/.htaccess')) @file_put_contents($dir.'/.htaccess',"Require all denied\nDeny from all\n");
        if(!is_file($dir.'/web.config')) @file_put_contents($dir.'/web.config',"<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><authorization><remove users=\"*\" roles=\"\" verbs=\"\"/><add accessType=\"Deny\" users=\"*\"/></authorization></system.webServer></configuration>");
        if(!is_file($dir.'/index.php')) @file_put_contents($dir.'/index.php',"<?php http_response_code(404); exit;\n"); return $dir;
    }

    private static function completeness_warnings(int $campId): array {
        global $wpdb; $r=BCS_DB::table('registrations'); $i=BCS_DB::table('invoices'); $warnings=[];
        $pending=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$r} WHERE camp_id=%d AND status<>'cancelled' AND agreement_status<>'accepted'",$campId));
        $unpaid=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$r} WHERE camp_id=%d AND status<>'cancelled' AND total_amount>0 AND paid_amount<total_amount",$campId));
        $invoiceProblems=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT rr.id) FROM {$r} rr LEFT JOIN {$i} ii ON ii.registration_id=rr.id WHERE rr.camp_id=%d AND rr.status<>'cancelled' AND rr.invoice_requested=1 AND (ii.id IS NULL OR ii.status<>'sent')",$campId));
        if($pending)$warnings[]='Niepodpisane umowy: '.$pending.'.'; if($unpaid)$warnings[]='Niepełne rozliczenia: '.$unpaid.'.'; if($invoiceProblems)$warnings[]='Faktury wymagające uwagi: '.$invoiceProblems.'.'; return $warnings;
    }

    private static function prepare_ksef_evidence_for_camp(int $campId): array {
        global $wpdb; $ids=$wpdb->get_col($wpdb->prepare("SELECT i.id FROM ".BCS_DB::table('invoices')." i JOIN ".BCS_DB::table('registrations')." r ON r.id=i.registration_id WHERE r.camp_id=%d AND i.ksef_status='accepted' ORDER BY i.id",$campId)); $warnings=[];
        foreach((array)$ids as $id){$result=self::ensure_invoice_ksef_evidence((int)$id,true);$warnings=array_merge($warnings,$result['warnings']);} return $warnings;
    }

    private static function readme(object $camp,string $status,array $warnings): string {
        $text="BASKETMANIA CAMP – ARCHIWUM TURNUSU\n\nTurnus: {$camp->name} (#{$camp->id})\nTermin: {$camp->start_date} – {$camp->end_date}\nUtworzono: ".BCS_Utils::now()."\nWersja systemu: ".(defined('BCS_VERSION')?BCS_VERSION:'1.08')."\nStatus: ".self::archive_status_label($status)."\n\n";
        $text.="Zawartość obejmuje eksporty CSV, dokumenty PDF, dowody KSeF, korespondencję, logi oraz zrzut SQL rekordów powiązanych z turnusem.\nPlik SQL zachowuje oryginalne ID rekordów. Nie zawiera tokenów API, haseł SMTP ani sekretów Stripe/KSeF.\nArchiwum zawiera dane osobowe, w tym możliwe dane zdrowotne. Przechowuj je w szyfrowanej, kontrolowanej lokalizacji.\n\n";
        return $text.($warnings?"UWAGI:\n- ".implode("\n- ",$warnings)."\n":"Brak ostrzeżeń o kompletności.\n");
    }

    private static function csv(array $header,array $rows): string {
        $f=fopen('php://temp','r+'); fwrite($f,"\xEF\xBB\xBF"); fputcsv($f,$header,';'); foreach($rows as $row) fputcsv($f,array_map(static fn($v)=>is_scalar($v)||$v===null?(string)$v:wp_json_encode($v,JSON_UNESCAPED_UNICODE),$row),';'); rewind($f);$out=stream_get_contents($f);fclose($f);return(string)$out;
    }

    private static function participants_csv(array $registrations,bool $sensitive): string {
        $rows=[];
        if($sensitive){$header=['ID','Uczestnik','PESEL','Data urodzenia','Informacje medyczne','Dieta i alergie','Specjalne potrzeby edukacyjne','Szczepienie tężec','Szczepienie błonica','Inne szczepienia','Upoważniony odbiór','Uwagi obozowe'];foreach($registrations as $r)$rows[]=[(int)$r->id,trim((string)$r->child_first_name.' '.(string)$r->child_last_name),(string)$r->child_pesel,(string)$r->child_birth_date,(string)$r->medical_notes,(string)$r->dietary_notes,(string)($r->special_educational_needs??''),(string)($r->vaccination_tetanus??''),(string)($r->vaccination_diphtheria??''),(string)($r->vaccination_other??''),(string)($r->authorized_pickup??''),(string)($r->camp_notes??'')];}
        else{$header=['ID','Status','Imię uczestnika','Nazwisko uczestnika','Klub','Rozmiar koszulki','Imię opiekuna','Nazwisko opiekuna','E-mail','Telefon','Adres','Cena','Wpłacono','Status umowy','Status faktury','Utworzono'];foreach($registrations as $r)$rows[]=[(int)$r->id,(string)$r->status,(string)$r->child_first_name,(string)$r->child_last_name,(string)$r->child_club,(string)$r->shirt_size,(string)$r->parent_first_name,(string)$r->parent_last_name,(string)$r->parent_email,(string)$r->parent_phone,(string)$r->parent_address,(string)$r->total_amount,(string)$r->paid_amount,(string)$r->agreement_status,(string)$r->invoice_status,(string)$r->created_at];}
        return self::csv($header,$rows);
    }

    private static function finances_csv(array $registrations,array $invoices): string {
        $byReg=[];foreach($invoices as $i)$byReg[(int)$i->registration_id]=$i;$rows=[];foreach($registrations as $r){$i=$byReg[(int)$r->id]??null;$rows[]=[(int)$r->id,trim((string)$r->child_first_name.' '.(string)$r->child_last_name),(string)$r->total_amount,(string)$r->paid_amount,number_format(max(0,(float)$r->total_amount-(float)$r->paid_amount),2,'.',''),$i?(string)$i->invoice_number:'',$i?(string)$i->status:'',$i?(string)$i->ksef_status:'',$i?(string)$i->ksef_number:''];} return self::csv(['ID zgłoszenia','Uczestnik','Należność','Wpłacono','Pozostało','Numer faktury','Status faktury','Status KSeF','Numer KSeF'],$rows);
    }

    private static function ksef_csv(array $invoices): string {
        $rows=[];foreach($invoices as $i)$rows[]=[(int)$i->id,(int)$i->registration_id,(string)$i->invoice_number,(string)$i->ksef_status,(string)$i->ksef_number,(string)$i->ksef_session_reference,(string)$i->ksef_invoice_reference,(string)$i->ksef_sent_at,(string)$i->ksef_accepted_at,(string)$i->ksef_xml_hash,!empty($i->ksef_xml_path)&&is_file((string)$i->ksef_xml_path)?'TAK':'NIE',!empty($i->ksef_remote_xml_path)&&is_file((string)$i->ksef_remote_xml_path)?'TAK':'NIE',!empty($i->ksef_upo_path)&&is_file((string)$i->ksef_upo_path)?'TAK':'NIE'];return self::csv(['ID faktury','ID zgłoszenia','Numer faktury','Status KSeF','Numer KSeF','Referencja sesji','Referencja faktury','Wysłano','Przyjęto','SHA-256 FA(3)','Lokalny XML','XML z KSeF','UPO'],$rows);
    }

    private static function logs_csv(int $campId): string {
        global $wpdb;$rows=$wpdb->get_results($wpdb->prepare("SELECT l.* FROM ".BCS_DB::table('logs')." l JOIN ".BCS_DB::table('registrations')." r ON r.id=l.registration_id WHERE r.camp_id=%d ORDER BY l.id",$campId));$out=[];foreach((array)$rows as $l)$out[]=[(int)$l->id,(int)$l->registration_id,(int)$l->agreement_id,(string)$l->event_type,(string)$l->event_data,(string)$l->ip,(string)$l->created_at];return self::csv(['ID','ID zgłoszenia','ID umowy','Typ zdarzenia','Dane zdarzenia','IP','Data'],$out);
    }

    private static function correspondence_json(int $registrationId): string {
        global $wpdb;$messages=$wpdb->get_results($wpdb->prepare("SELECT * FROM ".BCS_DB::table('messages')." WHERE registration_id=%d ORDER BY id",$registrationId),ARRAY_A);$mail=$wpdb->get_results($wpdb->prepare("SELECT * FROM ".BCS_DB::table('mail_messages')." WHERE registration_id=%d ORDER BY id",$registrationId),ARRAY_A);if(!$messages&&!$mail)return'';return(string)wp_json_encode(['registration_id'=>$registrationId,'messages'=>$messages,'mailbox'=>$mail],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }

    private static function ksef_proof_array(object $i): array {
        return ['invoice_id'=>(int)$i->id,'registration_id'=>(int)$i->registration_id,'invoice_number'=>(string)$i->invoice_number,'ksef_status'=>(string)$i->ksef_status,'ksef_number'=>(string)$i->ksef_number,'environment'=>(string)$i->ksef_environment_used,'session_reference'=>(string)$i->ksef_session_reference,'invoice_reference'=>(string)$i->ksef_invoice_reference,'sent_at'=>(string)$i->ksef_sent_at,'accepted_at'=>(string)$i->ksef_accepted_at,'status_code'=>(string)$i->ksef_status_code,'status_description'=>(string)$i->ksef_status_description,'fa3_sha256'=>(string)$i->ksef_xml_hash,'local_fa3_present'=>!empty($i->ksef_xml_path)&&is_file((string)$i->ksef_xml_path),'remote_xml_present'=>!empty($i->ksef_remote_xml_path)&&is_file((string)$i->ksef_remote_xml_path),'upo_present'=>!empty($i->ksef_upo_path)&&is_file((string)$i->ksef_upo_path)];
    }

    private static function sql_dump(object $camp): string {
        global $wpdb;$rid=array_map('intval',(array)$wpdb->get_col($wpdb->prepare("SELECT id FROM ".BCS_DB::table('registrations')." WHERE camp_id=%d ORDER BY id",(int)$camp->id)));$agreementIds=$rid?array_map('intval',(array)$wpdb->get_col("SELECT id FROM ".BCS_DB::table('agreements')." WHERE registration_id IN (".implode(',',$rid).")")):[];$invoiceIds=$rid?array_map('intval',(array)$wpdb->get_col("SELECT id FROM ".BCS_DB::table('invoices')." WHERE registration_id IN (".implode(',',$rid).")")):[];$consentContactIds=$rid&&self::table_exists('marketing_consent_events')?array_map('intval',(array)$wpdb->get_col("SELECT DISTINCT contact_id FROM ".BCS_DB::table('marketing_consent_events')." WHERE registration_id IN (".implode(',',$rid).")")):[];
        $dump="-- Basketmania Camp System – zrzut turnusu #".(int)$camp->id."\n-- Utworzono: ".BCS_Utils::now()." | wersja: ".(defined('BCS_VERSION')?BCS_VERSION:'1.08')."\n-- UWAGA: przy odtwarzaniu na innej instalacji sprawdź prefiks tabel. Zrzut nie zawiera sekretów API/SMTP/Stripe/KSeF.\n\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSTART TRANSACTION;\n\n";
        $organizerRows=$wpdb->get_results($wpdb->prepare("SELECT * FROM ".BCS_DB::table('organizers')." WHERE id=%d",(int)$camp->organizer_id),ARRAY_A);foreach($organizerRows as &$row){foreach(array_keys($row) as $column)if(preg_match('/(?:secret|token|ciphertext|nonce|password|webhook)/i',(string)$column))$row[$column]=null;}unset($row);
        $dump.=self::dump_rows(BCS_DB::table('organizers'),$organizerRows);$dump.=self::dump_rows(BCS_DB::table('camps'),$wpdb->get_results($wpdb->prepare("SELECT * FROM ".BCS_DB::table('camps')." WHERE id=%d",(int)$camp->id),ARRAY_A));
        if($rid){$in=implode(',',$rid);foreach(['registrations','agreements','agreement_versions','payments','activities','invoices','messages','mail_messages','logs'] as $name){if(!self::table_exists($name))continue;$column=$name==='registrations'?'id':'registration_id';$dump.=self::dump_rows(BCS_DB::table($name),$wpdb->get_results("SELECT * FROM ".BCS_DB::table($name)." WHERE {$column} IN ({$in}) ORDER BY id",ARRAY_A));}if($agreementIds&&self::table_exists('otp'))$dump.=self::dump_rows(BCS_DB::table('otp'),$wpdb->get_results("SELECT * FROM ".BCS_DB::table('otp')." WHERE agreement_id IN (".implode(',',$agreementIds).") ORDER BY id",ARRAY_A));if(self::table_exists('ksef_test_documents'))$dump.=self::dump_rows(BCS_DB::table('ksef_test_documents'),$wpdb->get_results("SELECT * FROM ".BCS_DB::table('ksef_test_documents')." WHERE registration_id IN ({$in}) ORDER BY id",ARRAY_A));if(self::table_exists('marketing_consent_events'))$dump.=self::dump_rows(BCS_DB::table('marketing_consent_events'),$wpdb->get_results("SELECT * FROM ".BCS_DB::table('marketing_consent_events')." WHERE registration_id IN ({$in}) ORDER BY id",ARRAY_A));}
        if($invoiceIds&&self::table_exists('ksef_operations'))$dump.=self::dump_rows(BCS_DB::table('ksef_operations'),$wpdb->get_results("SELECT * FROM ".BCS_DB::table('ksef_operations')." WHERE invoice_id IN (".implode(',',$invoiceIds).") ORDER BY id",ARRAY_A));if($consentContactIds&&self::table_exists('marketing_contacts'))$dump.=self::dump_rows(BCS_DB::table('marketing_contacts'),$wpdb->get_results("SELECT * FROM ".BCS_DB::table('marketing_contacts')." WHERE id IN (".implode(',',$consentContactIds).") ORDER BY id",ARRAY_A));return $dump."COMMIT;\nSET FOREIGN_KEY_CHECKS=1;\n";
    }

    private static function schema_dump(): string {
        global $wpdb;$tables=['organizers','camps','registrations','agreements','agreement_versions','otp','payments','activities','invoices','messages','mail_messages','logs','ksef_operations','ksef_test_documents','marketing_contacts','marketing_consent_events'];$out="-- Struktura tabel używanych w archiwum Basketmania Camp\n-- Wygenerowano: ".BCS_Utils::now()."\n\nSET FOREIGN_KEY_CHECKS=0;\n\n";foreach($tables as $name){if(!self::table_exists($name))continue;$table=BCS_DB::table($name);$row=$wpdb->get_row("SHOW CREATE TABLE `".str_replace('`','``',$table)."`",ARRAY_N);if(is_array($row)&&!empty($row[1]))$out.=(string)$row[1].";\n\n";}return $out."SET FOREIGN_KEY_CHECKS=1;\n";
    }

    private static function table_exists(string $name): bool {global $wpdb;$table=BCS_DB::table($name);return(string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))===$table;}

    private static function dump_rows(string $table,array $rows): string {
        if(!$rows)return'';global $wpdb;$out="-- {$table}\n";foreach($rows as $row){$columns=array_keys($row);$quoted=implode(',',array_map(static fn($c)=>'`'.str_replace('`','``',(string)$c).'`',$columns));$values=[];foreach($row as $value)$values[]=$value===null?'NULL':(string)$wpdb->prepare('%s',(string)$value);$out.='INSERT INTO `'.str_replace('`','``',$table).'` ('.$quoted.') VALUES ('.implode(',',$values).");\n";}return$out."\n";
    }

    public static function download_url(int $archiveId): string {return wp_nonce_url(add_query_arg(['action'=>self::DOWNLOAD_ACTION,'archive_id'=>$archiveId],admin_url('admin-post.php')),self::DOWNLOAD_ACTION.'_'.$archiveId);}

    public static function handle_download(): void {
        if(!current_user_can('manage_options'))wp_die('Brak uprawnień.');$archiveId=absint($_GET['archive_id']??0);check_admin_referer(self::DOWNLOAD_ACTION.'_'.$archiveId);global $wpdb;$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::archives_table()." WHERE id=%d",$archiveId));if(!$row)wp_die('Nie znaleziono archiwum.');$base=realpath(self::archive_dir());$path=realpath((string)$row->file_path);if(!$base||!$path||!str_starts_with($path,$base.DIRECTORY_SEPARATOR)||!is_file($path))wp_die('Plik archiwum jest niedostępny.');BCS_Utils::log('audit_camp_archive_download',['module'=>'Turnusy i archiwum','camp_id'=>(int)$row->camp_id,'archive_id'=>$archiveId,'sha256'=>(string)$row->sha256],null,null);nocache_headers();header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="'.sanitize_file_name((string)$row->file_name).'"; filename*=UTF-8\'\''.rawurlencode((string)$row->file_name));header('Content-Length: '.filesize($path));header('X-Content-Type-Options: nosniff');readfile($path);exit;
    }
}
