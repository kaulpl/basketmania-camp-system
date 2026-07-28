<?php
if (!defined('ABSPATH')) exit;

/** Administracja fundamentem KSeF TEST. */
final class BCS_KSeF_Admin {
    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'menu'], 30);
        add_action('admin_init', [__CLASS__, 'save_organizer_fields'], 5);
        add_action('admin_footer', [__CLASS__, 'inject_organizer_panel'], 20);
        add_action('wp_ajax_bcs_ksef_test_connection_072', [__CLASS__, 'ajax_test_connection']);
        add_action('wp_ajax_bcs_ksef_prepare_xml_072', [__CLASS__, 'ajax_prepare_xml']);
        add_action('admin_post_bcs_ksef_download_xml_072', [__CLASS__, 'download_xml']);
    }

    public static function menu(): void {
        add_submenu_page('bcs-dashboard', 'KSeF TEST', 'KSeF TEST', 'manage_options', 'bcs-ksef', [__CLASS__, 'page']);
    }

    private static function organizer(int $id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.BCS_DB::table('organizers').' WHERE id=%d', $id)) ?: null;
    }

    public static function save_organizer_fields(): void {
        if (!current_user_can('manage_options') || empty($_POST['bcs_save_organizer']) || empty($_POST['bcs_ksef_panel_present'])) return;
        check_admin_referer('bcs_save_organizer');
        $id = absint($_POST['organizer_id'] ?? 0);
        if (!$id || !self::organizer($id)) return;

        global $wpdb;
        $data = [
            'ksef_enabled' => isset($_POST['ksef_enabled']) ? 1 : 0,
            'ksef_environment' => 'test',
            'ksef_context_nip' => preg_replace('/\D+/', '', (string)wp_unslash($_POST['ksef_context_nip'] ?? '')),
            'ksef_country_code' => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)wp_unslash($_POST['ksef_country_code'] ?? 'PL')), 0, 2)) ?: 'PL',
            'ksef_address_l1' => sanitize_text_field(wp_unslash($_POST['ksef_address_l1'] ?? '')),
            'ksef_address_l2' => sanitize_text_field(wp_unslash($_POST['ksef_address_l2'] ?? '')),
            'ksef_anonymize_test' => isset($_POST['ksef_anonymize_test']) ? 1 : 0,
        ];

        if (!empty($_POST['ksef_remove_token'])) {
            $data['ksef_token_ciphertext'] = null;
            $data['ksef_token_nonce'] = null;
            $data['ksef_token_configured_at'] = null;
        } else {
            $token = trim((string)wp_unslash($_POST['ksef_token'] ?? ''));
            if ($token !== '') {
                try {
                    $encrypted = BCS_KSeF_Secret::encrypt($token);
                    $data['ksef_token_ciphertext'] = $encrypted['ciphertext'];
                    $data['ksef_token_nonce'] = $encrypted['nonce'];
                    $data['ksef_token_configured_at'] = BCS_Utils::now();
                } catch (Throwable $exception) {
                    $data['ksef_last_test_at'] = BCS_Utils::now();
                    $data['ksef_last_test_status'] = 'error';
                    $data['ksef_last_test_message'] = 'Nie zapisano tokenu: '.$exception->getMessage();
                }
            }
        }
        $wpdb->update(BCS_DB::table('organizers'), $data, ['id'=>$id]);
    }

    public static function inject_organizer_panel(): void {
        if (!current_user_can('manage_options')) return;
        $page = sanitize_key(wp_unslash($_GET['page'] ?? ''));
        $id = absint($_GET['edit'] ?? 0);
        if ($page !== 'bcs-organizers' || !$id) return;
        $organizer = self::organizer($id);
        if (!$organizer) return;
        $tokenConfigured = BCS_KSeF_Secret::configured($organizer);
        $keyReady = BCS_KSeF_Config::master_key_available();
        $nonce = wp_create_nonce('bcs_ksef_organizer_'.$id);
        ?>
        <template id="bcs-ksef-organizer-template-072">
            <div class="bcs-subpanel bcs-ksef-organizer-panel-072">
                <input type="hidden" name="bcs_ksef_panel_present" value="1">
                <h3>KSeF API 2.0 – środowisko TEST</h3>
                <p class="description">Etap 0.72 przygotowuje bezpieczną konfigurację, sprawdzenie połączenia i XML FA(3). Nie wysyła jeszcze faktur do KSeF.</p>
                <div class="bcs-form-grid">
                    <label class="bcs-checkbox"><input type="checkbox" name="ksef_enabled" value="1" <?php checked((int)($organizer->ksef_enabled ?? 0), 1); ?>><span>Włącz przygotowanie KSeF dla Organizatora</span></label>
                    <label><span>Środowisko</span><select name="ksef_environment" disabled><option value="test" selected>TEST – api-test.ksef.mf.gov.pl</option></select><input type="hidden" name="ksef_environment" value="test"></label>
                    <label><span>NIP kontekstu TEST</span><input type="text" inputmode="numeric" maxlength="10" name="ksef_context_nip" value="<?php echo esc_attr((string)($organizer->ksef_context_nip ?? '')); ?>" placeholder="10 cyfr – dane testowe"></label>
                    <label><span>Kod kraju</span><input type="text" maxlength="2" name="ksef_country_code" value="<?php echo esc_attr((string)($organizer->ksef_country_code ?? 'PL')); ?>"></label>
                    <label><span>Adres KSeF – linia 1</span><input type="text" name="ksef_address_l1" value="<?php echo esc_attr((string)($organizer->ksef_address_l1 ?? '')); ?>" placeholder="ulica i numer"></label>
                    <label><span>Adres KSeF – linia 2</span><input type="text" name="ksef_address_l2" value="<?php echo esc_attr((string)($organizer->ksef_address_l2 ?? '')); ?>" placeholder="kod pocztowy i miejscowość"></label>
                    <label class="bcs-span-2"><span>Token KSeF TEST</span><input type="password" autocomplete="new-password" name="ksef_token" placeholder="<?php echo $tokenConfigured ? 'Token zapisany i zaszyfrowany – pozostaw puste, aby zachować' : 'Wklej token KSeF TEST'; ?>"></label>
                    <label class="bcs-checkbox"><input type="checkbox" name="ksef_anonymize_test" value="1" <?php checked((int)($organizer->ksef_anonymize_test ?? 1), 1); ?>><span>Anonimizuj dane w XML środowiska TEST</span></label>
                    <?php if ($tokenConfigured): ?><label class="bcs-checkbox"><input type="checkbox" name="ksef_remove_token" value="1"><span>Usuń zapisany token</span></label><?php endif; ?>
                </div>
                <div class="bcs-ksef-security-072 <?php echo $keyReady ? 'is-ok' : 'is-error'; ?>">
                    <strong><?php echo $keyReady ? '✓ Klucz szyfrujący serwera jest dostępny.' : '✕ Brak klucza szyfrującego BCS_KSEF_SECRET_KEY.'; ?></strong>
                    <?php if (!$keyReady): ?><p>Dodaj w <code>wp-config.php</code>: <code><?php echo esc_html(BCS_KSeF_Config::master_key_help()); ?></code></p><?php endif; ?>
                    <p>Token: <?php echo $tokenConfigured ? '<strong>zapisany w formie zaszyfrowanej</strong>' : 'nie skonfigurowano'; ?>.</p>
                </div>
                <p><button type="button" class="button bcs-ksef-test-072" data-organizer="<?php echo (int)$id; ?>" data-nonce="<?php echo esc_attr($nonce); ?>">Sprawdź połączenie z KSeF API TEST</button> <span class="bcs-ksef-test-result-072"></span></p>
                <p class="description">Test sprawdza publiczny endpoint wyzwania API. Weryfikacja tokenu i rzeczywista wysyłka faktury zostaną uruchomione w etapie 0.73.</p>
                <?php if (!empty($organizer->ksef_last_test_at)): ?><p><strong>Ostatni test:</strong> <?php echo esc_html(BCS_Utils::format_datetime((string)$organizer->ksef_last_test_at)); ?> – <?php echo esc_html((string)$organizer->ksef_last_test_message); ?></p><?php endif; ?>
            </div>
        </template>
        <style>
            .bcs-ksef-security-072{margin:14px 0;padding:12px 14px;border-left:4px solid #dba617;background:#fff8e5}.bcs-ksef-security-072.is-ok{border-color:#2e8b57;background:#eefaf3}.bcs-ksef-security-072.is-error{border-color:#c0392b;background:#fff1f0}.bcs-ksef-test-result-072{margin-left:8px;font-weight:600}.bcs-ksef-test-result-072.is-ok{color:#22713e}.bcs-ksef-test-result-072.is-error{color:#b42318}
        </style>
        <script>
        (() => {
            const template = document.getElementById('bcs-ksef-organizer-template-072');
            const form = document.querySelector('.wrap.bcs-admin form input[name="organizer_id"][value="<?php echo (int)$id; ?>"]')?.closest('form');
            if (!template || !form || form.querySelector('.bcs-ksef-organizer-panel-072')) return;
            const actions = form.querySelector('.bcs-form-actions');
            const panel = template.content.firstElementChild.cloneNode(true);
            actions ? form.insertBefore(panel, actions) : form.appendChild(panel);
            panel.querySelector('.bcs-ksef-test-072')?.addEventListener('click', async (event) => {
                const button = event.currentTarget; const result = panel.querySelector('.bcs-ksef-test-result-072');
                button.disabled = true; result.className = 'bcs-ksef-test-result-072'; result.textContent = 'Łączenie…';
                const data = new URLSearchParams({action:'bcs_ksef_test_connection_072', organizer_id:button.dataset.organizer, nonce:button.dataset.nonce});
                try {
                    const response = await fetch(window.ajaxurl, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body:data.toString()});
                    const json = await response.json(); const ok = Boolean(json.success);
                    result.classList.add(ok ? 'is-ok' : 'is-error'); result.textContent = json.data?.message || (ok ? 'Połączono.' : 'Błąd połączenia.');
                } catch (error) { result.classList.add('is-error'); result.textContent = 'Nie udało się odczytać odpowiedzi API.'; }
                finally { button.disabled = false; }
            });
        })();
        </script>
        <?php
    }

    public static function ajax_test_connection(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Brak uprawnień.'], 403);
        $id = absint($_POST['organizer_id'] ?? 0);
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        if (!$id || !wp_verify_nonce($nonce, 'bcs_ksef_organizer_'.$id)) wp_send_json_error(['message'=>'Sesja wygasła.'], 403);
        $organizer = self::organizer($id);
        if (!$organizer) wp_send_json_error(['message'=>'Nie znaleziono Organizatora.'], 404);
        $result = (new BCS_KSeF_Client('test'))->challenge();
        $success = $result['success'] && (!empty($result['data']['challenge']) || !empty($result['data']['timestamp']));
        $message = $success ? 'Połączenie z KSeF API TEST działa (HTTP '.$result['http_code'].').' : $result['message'];
        global $wpdb;
        $wpdb->update(BCS_DB::table('organizers'), [
            'ksef_last_test_at'=>BCS_Utils::now(),
            'ksef_last_test_status'=>$success ? 'success' : 'error',
            'ksef_last_test_message'=>$message,
        ], ['id'=>$id]);
        BCS_KSeF_FA3::operation(0, $id, 'Test połączenia z KSeF API', $success ? 'success' : 'error', null, ['http_code'=>$result['http_code']], '', $success ? '' : $message);
        if ($success) wp_send_json_success(['message'=>$message]);
        wp_send_json_error(['message'=>$message], 502);
    }

    public static function ajax_prepare_xml(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Brak uprawnień.'], 403);
        $invoiceId = absint($_POST['invoice_id'] ?? 0);
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        if (!$invoiceId || !wp_verify_nonce($nonce, 'bcs_ksef_invoice_'.$invoiceId)) wp_send_json_error(['message'=>'Sesja wygasła.'], 403);
        $result = BCS_KSeF_FA3::prepare_and_save($invoiceId);
        if (!empty($result['success'])) {
            $url = wp_nonce_url(admin_url('admin-post.php?action=bcs_ksef_download_xml_072&invoice_id='.$invoiceId), 'bcs_ksef_download_'.$invoiceId);
            wp_send_json_success(['message'=>$result['message'], 'download_url'=>$url, 'hash'=>$result['hash'] ?? '']);
        }
        $message = (string)($result['message'] ?? 'Nie udało się przygotować XML.');
        if (!empty($result['errors'])) $message .= ' '.implode(' ', array_map('strval', (array)$result['errors']));
        wp_send_json_error(['message'=>$message], 422);
    }

    public static function download_xml(): void {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        $invoiceId = absint($_GET['invoice_id'] ?? 0);
        check_admin_referer('bcs_ksef_download_'.$invoiceId);
        global $wpdb;
        $path = (string)$wpdb->get_var($wpdb->prepare('SELECT ksef_xml_path FROM '.BCS_DB::table('invoices').' WHERE id=%d', $invoiceId));
        $real = realpath($path); $base = realpath((string)wp_upload_dir()['basedir']);
        if (!$real || !$base || !str_starts_with($real, $base) || !is_file($real)) wp_die('Plik XML nie istnieje.');
        nocache_headers(); header('Content-Type: application/xml; charset=UTF-8'); header('Content-Disposition: attachment; filename="'.basename($real).'"'); header('Content-Length: '.filesize($real));
        readfile($real); exit;
    }

    private static function status_label(string $status): string {
        return [
            'not_sent'=>'Nie przygotowano', 'xml_ready'=>'XML gotowy', 'sending'=>'Wysyłanie', 'processing'=>'Przetwarzanie',
            'accepted'=>'Przyjęto w KSeF', 'rejected'=>'Odrzucono', 'connection_error'=>'Błąd połączenia',
        ][$status] ?? ($status !== '' ? $status : 'Nie przygotowano');
    }

    public static function page(): void {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $organizers = $wpdb->get_results('SELECT * FROM '.BCS_DB::table('organizers').' ORDER BY name');
        $invoices = $wpdb->get_results("SELECT i.*, r.invoice_buyer_name, r.parent_first_name, r.parent_last_name, o.name organizer_name
            FROM ".BCS_DB::table('invoices')." i
            JOIN ".BCS_DB::table('registrations')." r ON r.id=i.registration_id
            JOIN ".BCS_DB::table('organizers')." o ON o.id=i.organizer_id
            ORDER BY i.id DESC LIMIT 100");
        echo '<div class="wrap bcs-admin bcs-ksef-page-072"><div class="bcs-page-head"><div><h1>KSeF TEST</h1><p>Etap 0.72: konfiguracja Organizatorów, połączenie z API i przygotowanie XML FA(3). Bez wysyłki faktur.</p></div><span class="bcs-version-label">API '.esc_html(BCS_KSeF_Config::API_VERSION).'</span></div>';
        echo '<div class="notice notice-info inline"><p><strong>Bezpieczeństwo:</strong> środowisko TEST wymusza anonimizację danych. Token jest szyfrowany kluczem z <code>wp-config.php</code>. Przycisk „Generuj fakturę” zachowuje obecny proces do czasu etapu 0.73.</p></div>';
        echo '<div class="bcs-list-grid">';
        foreach ($organizers as $organizer) {
            $configured = BCS_KSeF_Secret::configured($organizer);
            echo '<article class="bcs-list-card"><div class="bcs-card-top"><div><span class="bcs-badge '.((int)($organizer->ksef_enabled ?? 0)?'status-open':'status-draft').'">'.((int)($organizer->ksef_enabled ?? 0)?'KSeF włączony':'KSeF wyłączony').'</span><h2>'.esc_html($organizer->name).'</h2></div><span class="bcs-id">#'.(int)$organizer->id.'</span></div><dl><div><dt>Środowisko</dt><dd>TEST</dd></div><div><dt>Token</dt><dd>'.($configured?'Zaszyfrowany':'Brak').'</dd></div><div><dt>NIP kontekstu</dt><dd>'.esc_html((string)($organizer->ksef_context_nip ?: '—')).'</dd></div><div><dt>Ostatni test</dt><dd>'.esc_html(!empty($organizer->ksef_last_test_at)?BCS_Utils::format_datetime((string)$organizer->ksef_last_test_at):'—').'</dd></div></dl><a class="button" href="'.esc_url(admin_url('admin.php?page=bcs-organizers&edit='.(int)$organizer->id)).'">Konfiguruj</a></article>';
        }
        if (!$organizers) echo '<div class="bcs-empty">Brak Organizatorów.</div>';
        echo '</div><section class="bcs-panel"><div class="bcs-panel-head"><div><h2>Faktury i XML FA(3)</h2><p>XML zawiera wyłącznie dane testowe, gdy anonimizacja jest aktywna.</p></div></div><div class="bcs-table-wrap"><table class="widefat striped"><thead><tr><th>Faktura</th><th>Organizator</th><th>Nabywca</th><th>Brutto</th><th>Status KSeF</th><th>XML</th></tr></thead><tbody>';
        foreach ($invoices as $invoice) {
            $nonce = wp_create_nonce('bcs_ksef_invoice_'.(int)$invoice->id);
            $buyer = trim((string)($invoice->invoice_buyer_name ?: ($invoice->parent_first_name.' '.$invoice->parent_last_name)));
            $download = !empty($invoice->ksef_xml_path) && is_file((string)$invoice->ksef_xml_path) ? wp_nonce_url(admin_url('admin-post.php?action=bcs_ksef_download_xml_072&invoice_id='.(int)$invoice->id), 'bcs_ksef_download_'.(int)$invoice->id) : '';
            echo '<tr data-invoice="'.(int)$invoice->id.'"><td><strong>'.esc_html($invoice->invoice_number).'</strong><br><small>'.esc_html((string)$invoice->issue_date).'</small></td><td>'.esc_html($invoice->organizer_name).'</td><td>'.esc_html($buyer).'</td><td>'.esc_html(number_format((float)$invoice->gross_amount,2,',',' ').' zł').'</td><td class="bcs-ksef-status-072">'.esc_html(self::status_label((string)$invoice->ksef_status)).'</td><td><button type="button" class="button bcs-ksef-prepare-072" data-invoice="'.(int)$invoice->id.'" data-nonce="'.esc_attr($nonce).'">Przygotuj XML FA(3)</button> <span class="bcs-ksef-xml-link-072">'.($download?'<a class="button" href="'.esc_url($download).'">Pobierz XML</a>':'').'</span><div class="bcs-ksef-row-result-072"></div></td></tr>';
        }
        if (!$invoices) echo '<tr><td colspan="6">Brak wygenerowanych faktur.</td></tr>';
        echo '</tbody></table></div></section></div>';
        ?>
        <style>.bcs-ksef-row-result-072{margin-top:6px;font-size:12px}.bcs-ksef-row-result-072.is-ok{color:#22713e}.bcs-ksef-row-result-072.is-error{color:#b42318}.bcs-ksef-page-072 .bcs-table-wrap{overflow:auto}</style>
        <script>
        document.addEventListener('click', async (event) => {
            const button = event.target.closest('.bcs-ksef-prepare-072'); if (!button) return;
            const row = button.closest('tr'); const result = row.querySelector('.bcs-ksef-row-result-072'); button.disabled = true; result.className='bcs-ksef-row-result-072'; result.textContent='Przygotowywanie XML…';
            const data = new URLSearchParams({action:'bcs_ksef_prepare_xml_072', invoice_id:button.dataset.invoice, nonce:button.dataset.nonce});
            try {
                const response = await fetch(window.ajaxurl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:data.toString()}); const json=await response.json(); const ok=Boolean(json.success);
                result.classList.add(ok?'is-ok':'is-error'); result.textContent=json.data?.message||(ok?'Gotowe.':'Błąd.');
                if(ok){row.querySelector('.bcs-ksef-status-072').textContent='XML gotowy';row.querySelector('.bcs-ksef-xml-link-072').innerHTML='<a class="button" href="'+json.data.download_url+'">Pobierz XML</a>';}
            } catch(error){result.classList.add('is-error');result.textContent='Nie udało się odczytać odpowiedzi serwera.';} finally {button.disabled=false;}
        });
        </script>
        <?php
    }
}
