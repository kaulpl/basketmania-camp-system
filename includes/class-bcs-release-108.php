<?php
if (!defined('ABSPATH')) exit;

/**
 * 1.08 – archiwizacja zakończonych turnusów.
 *
 * Archiwum jest kompletną paczką administracyjną: dokumenty, eksporty,
 * manifest, sumy SHA-256 i selektywny zrzut SQL danych powiązanych z turnusem.
 * Paczki nie są udostępniane publicznym URL-em – pobranie przechodzi przez
 * autoryzowany admin-post.
 */
final class BCS_Release_108 {
    private const ARCHIVE_ACTION = 'bcs_archive_camp_108';
    private const DOWNLOAD_ACTION = 'bcs_download_camp_archive_108';
    private const REBUILD_ACTION = 'bcs_rebuild_camp_archive_108';

    public static function init(): void {
        add_action('admin_init', [__CLASS__, 'ensure_schema'], 2);
        add_action('admin_menu', [__CLASS__, 'menu'], 95);
        add_action('admin_post_'.self::ARCHIVE_ACTION, [__CLASS__, 'handle_archive']);
        add_action('admin_post_'.self::REBUILD_ACTION, [__CLASS__, 'handle_rebuild']);
        add_action('admin_post_'.self::DOWNLOAD_ACTION, [__CLASS__, 'handle_download']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assets'], 99);
    }

    public static function ensure_schema(): void {
        global $wpdb;
        $table = BCS_DB::table('camps');
        $columns = [
            'archived_at' => 'DATETIME NULL',
            'archived_by' => 'BIGINT UNSIGNED NULL',
            'archive_path' => 'TEXT NULL',
            'archive_hash' => 'CHAR(64) NULL',
            'archive_status' => "VARCHAR(30) NULL",
            'archive_size' => 'BIGINT UNSIGNED NULL',
            'archive_manifest' => 'LONGTEXT NULL',
            'archive_created_at' => 'DATETIME NULL',
        ];
        foreach ($columns as $column=>$definition) {
            $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column));
            if ($exists === null) $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }

    public static function menu(): void {
        add_submenu_page('bcs-dashboard', 'Archiwum turnusów', 'Archiwum turnusów', 'manage_options', 'bcs-camp-archive', [__CLASS__, 'page']);
    }

    public static function admin_assets(string $hook): void {
        if (!str_contains($hook, 'bcs-camps')) return;
        $script = <<<'JS'
(function(){
  document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('.bcs-badge.status-archived').forEach(function(b){ b.textContent='Zarchiwizowany'; });
    document.querySelectorAll('.bcs-list-card').forEach(function(card){
      var badge=card.querySelector('.bcs-badge.status-archived');
      if(!badge) return;
      card.classList.add('bcs-camp-archived-108');
      var edit=card.querySelector('a[href*="page=bcs-camps&edit="]');
      if(edit){ edit.textContent='Podgląd'; edit.href=edit.href.replace('page=bcs-camps&edit=','page=bcs-camp-archive&camp_id='); }
      var del=card.querySelector('form.bcs-inline-delete'); if(del) del.remove();
      var actions=card.querySelector('.bcs-card-actions');
      if(actions && !actions.querySelector('a[href*="bcs-camp-archive"]')){
        var m=(card.querySelector('.bcs-id')||{}).textContent||''; var id=m.replace(/\D/g,'');
        if(id){ var a=document.createElement('a');a.className='button button-primary';a.textContent='Archiwum';a.href=window.ajaxurl.replace('admin-ajax.php','admin.php?page=bcs-camp-archive&camp_id='+id);actions.appendChild(a); }
      }
    });
  });
})();
JS;
        wp_add_inline_script('bcs-admin', $script, 'after');
    }

    private static function storage_dir(): string {
        $dir = trailingslashit(WP_CONTENT_DIR).'bcs-private-archives';
        if (!is_dir($dir)) wp_mkdir_p($dir);
        if (is_dir($dir)) {
            $ht = $dir.'/.htaccess';
            if (!is_file($ht)) @file_put_contents($ht, "Order allow,deny\nDeny from all\nRequire all denied\n", LOCK_EX);
            $index = $dir.'/index.php';
            if (!is_file($index)) @file_put_contents($index, "<?php http_response_code(404); exit;\n", LOCK_EX);
        }
        return $dir;
    }

    private static function camp(int $campId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT c.*,o.name organizer_name,o.nip organizer_nip FROM ".BCS_DB::table('camps')." c LEFT JOIN ".BCS_DB::table('organizers')." o ON o.id=c.organizer_id WHERE c.id=%d",
            $campId
        )) ?: null;
    }

    private static function registration_ids(int $campId): array {
        global $wpdb;
        return array_map('intval', (array)$wpdb->get_col($wpdb->prepare("SELECT id FROM ".BCS_DB::table('registrations')." WHERE camp_id=%d ORDER BY id", $campId)));
    }

    public static function page(): void {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $selected = absint($_GET['camp_id'] ?? 0);
        $rows = $wpdb->get_results(
            "SELECT c.*,o.name organizer_name,COUNT(r.id) registrations " .
            "FROM ".BCS_DB::table('camps')." c LEFT JOIN ".BCS_DB::table('organizers')." o ON o.id=c.organizer_id " .
            "LEFT JOIN ".BCS_DB::table('registrations')." r ON r.camp_id=c.id " .
            "GROUP BY c.id ORDER BY c.start_date DESC,c.id DESC"
        );
        echo '<div class="wrap bcs-admin"><div class="bcs-page-head"><div><h1>Archiwum turnusów</h1><p>Zamknięcie sezonu, paczki dokumentacyjne i kopie odtworzeniowe danych.</p></div></div>';
        if (!empty($_GET['archived'])) echo '<div class="notice notice-success"><p>Archiwum turnusu zostało utworzone.</p></div>';
        if (!empty($_GET['rebuilt'])) echo '<div class="notice notice-success"><p>Paczka archiwalna została wygenerowana ponownie.</p></div>';
        if (!empty($_GET['archive_error'])) echo '<div class="notice notice-error"><p>'.esc_html(sanitize_text_field(wp_unslash($_GET['archive_error']))).'</p></div>';
        echo '<section class="bcs-panel"><h2>Turnusy</h2><div class="bcs-table-wrap"><table class="widefat striped"><thead><tr><th>Turnus</th><th>Termin</th><th>Uczestnicy</th><th>Status</th><th>Archiwum</th><th></th></tr></thead><tbody>';
        foreach ((array)$rows as $row) {
            $archived = (string)$row->status === 'archived';
            $status = $archived ? 'Zarchiwizowany' : (['open'=>'Otwarte','closed'=>'Zamknięte','draft'=>'Szkic'][(string)$row->status] ?? (string)$row->status);
            $archive = $row->archive_created_at ? BCS_Utils::format_datetime((string)$row->archive_created_at) : '—';
            echo '<tr'.($selected===(int)$row->id?' class="active"':'').'><td><strong>'.esc_html((string)$row->name).'</strong><br><small>#'.(int)$row->id.' · '.esc_html((string)$row->organizer_name).'</small></td><td>'.esc_html((string)$row->start_date).' – '.esc_html((string)$row->end_date).'</td><td>'.(int)$row->registrations.'</td><td><span class="bcs-badge status-'.esc_attr((string)$row->status).'">'.esc_html($status).'</span></td><td>'.esc_html($archive).($row->archive_status?'<br><small>'.esc_html(self::archive_status_label((string)$row->archive_status)).'</small>':'').'</td><td><a class="button" href="'.esc_url(admin_url('admin.php?page=bcs-camp-archive&camp_id='.(int)$row->id)).'">Szczegóły</a></td></tr>';
        }
        if (!$rows) echo '<tr><td colspan="6">Brak turnusów.</td></tr>';
        echo '</tbody></table></div></section>';
        if ($selected) self::camp_archive_panel($selected);
        echo '</div>';
    }

    private static function archive_status_label(string $status): string {
        return ['complete'=>'Archiwum kompletne','warnings'=>'Archiwum utworzone z uwagami','building'=>'Tworzenie archiwum','failed'=>'Błąd archiwizacji'][$status] ?? $status;
    }

    private static function camp_archive_panel(int $campId): void {
        $camp = self::camp($campId);
        if (!$camp) { echo '<div class="notice notice-error"><p>Nie znaleziono turnusu.</p></div>'; return; }
        $check = self::preflight($campId, false);
        echo '<section class="bcs-panel"><div class="bcs-panel-head"><div><h2>'.esc_html((string)$camp->name).'</h2><p>Kontrola kompletności przed zamknięciem i archiwizacją.</p></div></div>';
        echo '<div class="bcs-form-grid"><div><strong>Zgłoszenia</strong><p>'.(int)$check['counts']['registrations'].'</p></div><div><strong>Umowy niezamknięte</strong><p>'.(int)$check['counts']['agreements_open'].'</p></div><div><strong>Nierozliczone płatności</strong><p>'.(int)$check['counts']['unpaid'].'</p></div><div><strong>Faktury wymagające uwagi</strong><p>'.(int)$check['counts']['invoices_attention'].'</p></div></div>';
        if ($check['warnings']) {
            echo '<div class="notice notice-warning inline"><p><strong>Archiwum może zostać utworzone, ale wymaga uwagi:</strong></p><ul style="list-style:disc;padding-left:20px">';
            foreach ($check['warnings'] as $warning) echo '<li>'.esc_html($warning).'</li>';
            echo '</ul></div>';
        } else echo '<div class="notice notice-success inline"><p>Kontrola nie wykryła braków blokujących kompletność dokumentacji.</p></div>';

        if ((string)$camp->status !== 'archived') {
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" data-confirm="Zarchiwizować turnus i utworzyć pełną paczkę danych? Turnus zostanie oznaczony jako Zarchiwizowany.">';
            echo '<input type="hidden" name="action" value="'.esc_attr(self::ARCHIVE_ACTION).'"><input type="hidden" name="camp_id" value="'.$campId.'">';
            wp_nonce_field(self::ARCHIVE_ACTION.'_'.$campId);
            echo '<p><button class="button button-primary button-hero">Zamknij i zarchiwizuj turnus</button></p></form>';
        } else {
            echo '<div class="bcs-form-actions">';
            if (!empty($camp->archive_path) && is_file((string)$camp->archive_path)) {
                $url = wp_nonce_url(add_query_arg(['action'=>self::DOWNLOAD_ACTION,'camp_id'=>$campId], admin_url('admin-post.php')), self::DOWNLOAD_ACTION.'_'.$campId);
                echo '<a class="button button-primary" href="'.esc_url($url).'">Pobierz paczkę ZIP</a> ';
                echo '<span class="bcs-muted">SHA-256: <code>'.esc_html((string)$camp->archive_hash).'</code> · '.esc_html(size_format((int)$camp->archive_size)).'</span>';
            } else echo '<div class="notice notice-warning inline"><p>Plik archiwum nie jest dostępny na serwerze. Możesz wygenerować go ponownie.</p></div>';
            echo '</div><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin-top:14px">';
            echo '<input type="hidden" name="action" value="'.esc_attr(self::REBUILD_ACTION).'"><input type="hidden" name="camp_id" value="'.$campId.'">';
            wp_nonce_field(self::REBUILD_ACTION.'_'.$campId);
            echo '<button class="button">Wygeneruj archiwum ponownie</button></form>';
            if (!empty($camp->archive_manifest)) {
                $manifest = json_decode((string)$camp->archive_manifest, true);
                if (is_array($manifest)) echo '<details style="margin-top:18px"><summary><strong>Manifest ostatniej paczki</strong></summary><pre style="white-space:pre-wrap;max-height:420px;overflow:auto">'.esc_html(wp_json_encode($manifest, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)).'</pre></details>';
            }
        }
        echo '</section>';
    }

    private static function preflight(int $campId, bool $refreshKsef): array {
        global $wpdb;
        $regs = BCS_DB::table('registrations');
        $invoices = BCS_DB::table('invoices');
        $counts = [
            'registrations'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$regs} WHERE camp_id=%d", $campId)),
            'agreements_open'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$regs} WHERE camp_id=%d AND status<>'cancelled' AND agreement_status NOT IN ('accepted','cancelled')", $campId)),
            'unpaid'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$regs} WHERE camp_id=%d AND status<>'cancelled' AND total_amount>paid_amount", $campId)),
            'invoices_attention'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$regs} r LEFT JOIN {$invoices} i ON i.registration_id=r.id WHERE r.camp_id=%d AND r.invoice_requested=1 AND (i.id IS NULL OR i.status<>'sent')", $campId)),
        ];
        $warnings = [];
        if ($counts['agreements_open']) $warnings[] = $counts['agreements_open'].' zgłoszeń nie ma finalnie zamkniętej umowy.';
        if ($counts['unpaid']) $warnings[] = $counts['unpaid'].' zgłoszeń nie jest w pełni rozliczonych.';
        if ($counts['invoices_attention']) $warnings[] = $counts['invoices_attention'].' faktur wymaganych przez rodziców nie ma statusu wysłanej.';

        $invoiceRows = $wpdb->get_results($wpdb->prepare("SELECT i.* FROM {$invoices} i JOIN {$regs} r ON r.id=i.registration_id WHERE r.camp_id=%d ORDER BY i.id", $campId));
        foreach ((array)$invoiceRows as $invoice) {
            if ((string)$invoice->ksef_status !== 'accepted') {
                if (!in_array((string)$invoice->ksef_status, ['not_sent',''], true)) $warnings[] = 'Faktura '.(string)$invoice->invoice_number.' nie ma statusu „przyjęta” w KSeF ('.(string)$invoice->ksef_status.').';
                continue;
            }
            if ($refreshKsef) {
                if (empty($invoice->ksef_remote_xml_path) || !is_file((string)$invoice->ksef_remote_xml_path)) BCS_KSeF_Service::fetch_remote_xml((int)$invoice->id);
                if (empty($invoice->ksef_upo_path) || !is_file((string)$invoice->ksef_upo_path)) BCS_KSeF_Service::refresh_status((int)$invoice->id);
                $invoice = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$invoices} WHERE id=%d", (int)$invoice->id));
            }
            if (empty($invoice->ksef_remote_xml_path) || !is_file((string)$invoice->ksef_remote_xml_path)) $warnings[] = 'Brak zapisanego XML pobranego z KSeF dla faktury '.(string)$invoice->invoice_number.'.';
            if (empty($invoice->ksef_upo_path) || !is_file((string)$invoice->ksef_upo_path)) $warnings[] = 'Brak lokalnego pliku UPO dla faktury '.(string)$invoice->invoice_number.'.';
        }
        return ['counts'=>$counts,'warnings'=>array_values(array_unique($warnings))];
    }

    public static function handle_archive(): void {
        self::guard();
        $campId = absint($_POST['camp_id'] ?? 0);
        check_admin_referer(self::ARCHIVE_ACTION.'_'.$campId);
        self::build_and_redirect($campId, false);
    }

    public static function handle_rebuild(): void {
        self::guard();
        $campId = absint($_POST['camp_id'] ?? 0);
        check_admin_referer(self::REBUILD_ACTION.'_'.$campId);
        self::build_and_redirect($campId, true);
    }

    private static function guard(): void {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
    }

    private static function build_and_redirect(int $campId, bool $rebuild): void {
        $result = self::build_archive($campId);
        if (empty($result['success'])) {
            wp_safe_redirect(add_query_arg(['page'=>'bcs-camp-archive','camp_id'=>$campId,'archive_error'=>(string)($result['message'] ?? 'Nie udało się utworzyć archiwum.')], admin_url('admin.php'))); exit;
        }
        wp_safe_redirect(add_query_arg(['page'=>'bcs-camp-archive','camp_id'=>$campId,$rebuild?'rebuilt':'archived'=>1], admin_url('admin.php'))); exit;
    }

    public static function build_archive(int $campId): array {
        self::ensure_schema();
        $camp = self::camp($campId);
        if (!$camp) return ['success'=>false,'message'=>'Nie znaleziono turnusu.'];
        if (!class_exists('ZipArchive')) return ['success'=>false,'message'=>'Serwer nie ma rozszerzenia PHP ZipArchive. Archiwizacja ZIP nie może zostać wykonana.'];

        global $wpdb;
        $camps = BCS_DB::table('camps');
        $wpdb->update($camps, ['archive_status'=>'building'], ['id'=>$campId]);
        $preflight = self::preflight($campId, true);
        $camp = self::camp($campId);
        $ids = self::registration_ids($campId);
        $stamp = BCS_Utils::today('Ymd').'-'.wp_date('His', BCS_Utils::timestamp(), BCS_Utils::timezone());
        $slug = sanitize_file_name((string)($camp->slug ?: 'turnus-'.$campId));
        $filename = 'basketmania-'.$slug.'-'.$stamp.'.zip';
        $path = trailingslashit(self::storage_dir()).$filename;
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE|ZipArchive::OVERWRITE) !== true) {
            $wpdb->update($camps, ['archive_status'=>'failed'], ['id'=>$campId]);
            return ['success'=>false,'message'=>'Nie udało się utworzyć pliku ZIP.'];
        }

        $checksums = [];
        $manifestFiles = [];
        $addString = static function(string $name, string $content) use ($zip, &$checksums, &$manifestFiles): void {
            $zip->addFromString($name, $content);
            $hash = hash('sha256', $content); $checksums[$name]=$hash; $manifestFiles[]=['path'=>$name,'sha256'=>$hash,'size'=>strlen($content)];
        };
        $addFile = static function(string $source, string $name) use ($zip, &$checksums, &$manifestFiles): void {
            if (!is_file($source) || !is_readable($source)) return;
            $zip->addFile($source, $name);
            $hash = hash_file('sha256', $source) ?: ''; $checksums[$name]=$hash; $manifestFiles[]=['path'=>$name,'sha256'=>$hash,'size'=>(int)filesize($source)];
        };

        $registrations = $ids ? $wpdb->get_results("SELECT * FROM ".BCS_DB::table('registrations')." WHERE id IN (".implode(',',array_map('intval',$ids)).") ORDER BY id", ARRAY_A) : [];
        $invoices = $ids ? $wpdb->get_results("SELECT * FROM ".BCS_DB::table('invoices')." WHERE registration_id IN (".implode(',',array_map('intval',$ids)).") ORDER BY id", ARRAY_A) : [];
        $payments = $ids ? $wpdb->get_results("SELECT * FROM ".BCS_DB::table('payments')." WHERE registration_id IN (".implode(',',array_map('intval',$ids)).") ORDER BY id", ARRAY_A) : [];
        $logs = $ids ? $wpdb->get_results("SELECT * FROM ".BCS_DB::table('logs')." WHERE registration_id IN (".implode(',',array_map('intval',$ids)).") ORDER BY id", ARRAY_A) : [];

        $addString('exports/zgloszenia.csv', self::csv($registrations));
        $addString('exports/faktury.csv', self::csv($invoices));
        $addString('exports/platnosci.csv', self::csv($payments));
        $addString('exports/logi.json', wp_json_encode($logs, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        $addString('database/camp-data.sql', self::sql_dump($campId, $ids));
        $addString('database/README-ODTWARZANIE.txt', self::restore_readme($campId));

        $docsRoot = class_exists('BCS_Document_Engine') ? BCS_Document_Engine::uploads_dir() : '';
        foreach ($ids as $rid) {
            $dir = $docsRoot ? trailingslashit($docsRoot).'registration-'.$rid : '';
            if ($dir && is_dir($dir)) self::add_directory_to_zip($zip, $dir, 'documents/registration-'.$rid, $checksums, $manifestFiles);
        }
        foreach ((array)$invoices as $invoice) {
            foreach (['file_path'=>'pdf','ksef_xml_path'=>'xml-fa3','ksef_remote_xml_path'=>'xml-ksef','ksef_upo_path'=>'upo'] as $field=>$kind) {
                $source = (string)($invoice[$field] ?? '');
                if ($source === '' || !is_file($source)) continue;
                $name = 'ksef-faktury/invoice-'.(int)$invoice['id'].'/'.$kind.'-'.basename($source);
                $addFile($source, $name);
            }
        }

        $manifest = [
            'format'=>'Basketmania Camp Archive',
            'archive_version'=>'1.08',
            'plugin_version'=>defined('BCS_VERSION')?BCS_VERSION:'',
            'generated_at'=>BCS_Utils::now(),
            'generated_by'=>get_current_user_id(),
            'database_prefix'=>$wpdb->prefix,
            'camp'=>['id'=>$campId,'name'=>(string)$camp->name,'slug'=>(string)$camp->slug,'start_date'=>(string)$camp->start_date,'end_date'=>(string)$camp->end_date,'organizer_id'=>(int)$camp->organizer_id,'organizer_name'=>(string)$camp->organizer_name],
            'counts'=>array_merge($preflight['counts'], ['invoices'=>count($invoices),'payments'=>count($payments),'logs'=>count($logs)]),
            'warnings'=>$preflight['warnings'],
            'status'=>$preflight['warnings']?'warnings':'complete',
            'ksef'=>self::ksef_manifest($invoices),
            'files'=>$manifestFiles,
            'security_note'=>'Paczka zawiera dane osobowe i może zawierać dane o zdrowiu. Nie zawiera jawnych sekretów konfiguracji Stripe/KSeF ani kodów OTP.',
        ];
        $manifestJson = wp_json_encode($manifest, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
        $addString('manifest.json', $manifestJson);
        $checksumText = '';
        foreach ($checksums as $name=>$hash) $checksumText .= $hash.'  '.$name."\n";
        $addString('checksums.sha256', $checksumText);
        $zip->close();

        if (!is_file($path)) {
            $wpdb->update($camps, ['archive_status'=>'failed'], ['id'=>$campId]);
            return ['success'=>false,'message'=>'ZIP nie został zapisany.'];
        }
        $hash = hash_file('sha256', $path) ?: '';
        $now = BCS_Utils::now();
        $wpdb->update($camps, [
            'status'=>'archived', 'archived_at'=>$camp->archived_at ?: $now, 'archived_by'=>$camp->archived_by ?: get_current_user_id(),
            'archive_path'=>$path, 'archive_hash'=>$hash, 'archive_status'=>$preflight['warnings']?'warnings':'complete',
            'archive_size'=>(int)filesize($path), 'archive_manifest'=>$manifestJson, 'archive_created_at'=>$now, 'updated_at'=>$now,
        ], ['id'=>$campId]);
        BCS_Utils::log('camp_archived', ['camp_id'=>$campId,'archive_status'=>$preflight['warnings']?'warnings':'complete','archive_hash'=>$hash,'warning_count'=>count($preflight['warnings'])], null, null);
        return ['success'=>true,'path'=>$path,'hash'=>$hash,'manifest'=>$manifest];
    }

    private static function csv(array $rows): string {
        if (!$rows) return "\xEF\xBB\xBF";
        $fp = fopen('php://temp', 'r+');
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, array_keys((array)$rows[0]), ';', '"', '\\');
        foreach ($rows as $row) fputcsv($fp, array_values((array)$row), ';', '"', '\\');
        rewind($fp); $out = stream_get_contents($fp); fclose($fp); return (string)$out;
    }

    private static function ksef_manifest(array $invoices): array {
        $out = [];
        foreach ($invoices as $i) {
            $out[] = [
                'invoice_id'=>(int)$i['id'], 'invoice_number'=>(string)$i['invoice_number'], 'status'=>(string)($i['ksef_status'] ?? ''),
                'ksef_number'=>(string)($i['ksef_number'] ?? ''), 'invoice_reference'=>(string)($i['ksef_invoice_reference'] ?? $i['ksef_reference'] ?? ''),
                'sent_at'=>(string)($i['ksef_sent_at'] ?? ''), 'accepted_at'=>(string)($i['ksef_accepted_at'] ?? ''),
                'local_fa3_xml'=>!empty($i['ksef_xml_path']) && is_file((string)$i['ksef_xml_path']),
                'remote_ksef_xml'=>!empty($i['ksef_remote_xml_path']) && is_file((string)$i['ksef_remote_xml_path']),
                'upo'=>!empty($i['ksef_upo_path']) && is_file((string)$i['ksef_upo_path']),
                'environment'=>(string)($i['ksef_environment_used'] ?? ''),
            ];
        }
        return $out;
    }

    private static function add_directory_to_zip(ZipArchive $zip, string $dir, string $prefix, array &$checksums, array &$manifestFiles): void {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;
            $source = $file->getPathname();
            $relative = ltrim(str_replace('\\','/', substr($source, strlen(rtrim($dir, '/\\')))), '/');
            $name = trim($prefix,'/').'/'.$relative;
            $zip->addFile($source, $name);
            $hash = hash_file('sha256',$source) ?: '';
            $checksums[$name]=$hash; $manifestFiles[]=['path'=>$name,'sha256'=>$hash,'size'=>(int)$file->getSize()];
        }
    }

    private static function sql_dump(int $campId, array $registrationIds): string {
        global $wpdb;
        $sql = "-- Basketmania Camp – selektywny zrzut turnusu #{$campId}\n-- Wygenerowano: ".BCS_Utils::now()."\n-- Importować wyłącznie do zgodnej wersji schematu Basketmania Camp.\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
        $specs = [];
        $specs[] = [BCS_DB::table('camps'), "id=".(int)$campId, []];
        $camp = self::camp($campId);
        if ($camp && $camp->organizer_id) $specs[] = [BCS_DB::table('organizers'), "id=".(int)$camp->organizer_id, self::organizer_secret_columns()];
        if ($registrationIds) {
            $ids = implode(',',array_map('intval',$registrationIds));
            foreach (['registrations','agreements','agreement_versions','payments','invoices','logs','activities','messages','mail_messages'] as $name) {
                $column = $name==='registrations' ? 'id' : 'registration_id';
                $specs[] = [BCS_DB::table($name), $column." IN ({$ids})", []];
            }
            $invoiceIds = array_map('intval',(array)$wpdb->get_col("SELECT id FROM ".BCS_DB::table('invoices')." WHERE registration_id IN ({$ids})"));
            if ($invoiceIds) {
                $ii=implode(',',$invoiceIds);
                if (self::table_exists(BCS_DB::table('ksef_operations'))) $specs[]=[BCS_DB::table('ksef_operations'),"invoice_id IN ({$ii})",[]];
            }
            if (self::table_exists(BCS_DB::table('ksef_test_documents'))) $specs[]=[BCS_DB::table('ksef_test_documents'),"registration_id IN ({$ids})",[]];
            if (self::table_exists(BCS_DB::table('marketing_consent_events'))) $specs[]=[BCS_DB::table('marketing_consent_events'),"registration_id IN ({$ids})",[]];
            $emails = array_values(array_unique(array_filter(array_map('sanitize_email',(array)$wpdb->get_col("SELECT parent_email FROM ".BCS_DB::table('registrations')." WHERE id IN ({$ids})")))));
            if ($emails && self::table_exists(BCS_DB::table('marketing_contacts'))) {
                $quoted = implode(',',array_map(static fn($e)=>$wpdb->prepare('%s',$e),$emails));
                $specs[]=[BCS_DB::table('marketing_contacts'),"email IN ({$quoted})",[]];
            }
        }
        foreach ($specs as [$table,$where,$omit]) $sql .= self::dump_table($table,$where,$omit);
        return $sql."SET FOREIGN_KEY_CHECKS=1;\n";
    }

    private static function organizer_secret_columns(): array {
        return ['stripe_test_secret_key','stripe_test_webhook_secret','stripe_live_secret_key','stripe_live_webhook_secret','ksef_token_ciphertext','ksef_token_nonce','ksef_production_token_ciphertext','ksef_production_token_nonce'];
    }

    private static function table_exists(string $table): bool {
        global $wpdb;
        return (string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table)) === $table;
    }

    private static function dump_table(string $table, string $where, array $omit): string {
        global $wpdb;
        if (!self::table_exists($table)) return '';
        $create = $wpdb->get_row("SHOW CREATE TABLE {$table}", ARRAY_N);
        $ddl = (string)($create[1] ?? '');
        if ($ddl === '') return '';
        $ddl = preg_replace('/^CREATE TABLE /i','CREATE TABLE IF NOT EXISTS ',$ddl);
        $rows = $wpdb->get_results("SELECT * FROM {$table} WHERE {$where}", ARRAY_A);
        $out = "-- {$table}\n{$ddl};\n";
        foreach ((array)$rows as $row) {
            foreach ($omit as $column) unset($row[$column]);
            if (!$row) continue;
            $columns = array_map(static fn($c)=>'`'.str_replace('`','``',$c).'`', array_keys($row));
            $values = [];
            foreach ($row as $value) $values[] = $value === null ? 'NULL' : $wpdb->prepare('%s',(string)$value);
            $out .= "INSERT INTO `{$table}` (".implode(',',$columns).") VALUES (".implode(',',$values).");\n";
        }
        return $out."\n";
    }

    private static function restore_readme(int $campId): string {
        return "BASKETMANIA CAMP – ARCHIWUM ODTWORZENIOWE TURNUSU #{$campId}\n\n".
            "Plik camp-data.sql zawiera rekordy Basketmania Camp powiązane z tym turnusem oraz definicje potrzebnych tabel.\n".
            "Nie jest to pełny backup WordPressa. Nie zawiera kont WordPress, ustawień innych wtyczek ani jawnych sekretów Stripe/KSeF.\n".
            "Nie eksportujemy kodów OTP.\n\n".
            "Odtwarzanie:\n1. Zainstaluj zgodną wersję Basketmania Camp System.\n2. Wykonaj pełną kopię docelowej bazy.\n3. Sprawdź prefiks tabel w manifest.json.\n4. W razie innego prefiksu zmień nazwy tabel w pliku SQL.\n5. Zaimportuj camp-data.sql do pustej/technicznej bazy lub świadomie połącz rekordy z istniejącą bazą.\n6. Skopiuj dokumenty z katalogu documents zgodnie z manifestem.\n\n".
            "Plik checksums.sha256 pozwala zweryfikować integralność zawartości paczki.\n";
    }

    public static function handle_download(): void {
        self::guard();
        $campId = absint($_GET['camp_id'] ?? 0);
        check_admin_referer(self::DOWNLOAD_ACTION.'_'.$campId);
        $camp = self::camp($campId);
        if (!$camp || empty($camp->archive_path) || !is_file((string)$camp->archive_path)) wp_die('Plik archiwum nie istnieje.');
        $path = realpath((string)$camp->archive_path);
        $root = realpath(self::storage_dir());
        if (!$path || !$root || !str_starts_with($path, $root.DIRECTORY_SEPARATOR)) wp_die('Nieprawidłowa ścieżka archiwum.');
        BCS_Utils::log('camp_archive_downloaded', ['camp_id'=>$campId,'archive_hash'=>(string)$camp->archive_hash], null, null);
        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="'.basename($path).'"');
        header('Content-Length: '.filesize($path));
        readfile($path); exit;
    }
}
