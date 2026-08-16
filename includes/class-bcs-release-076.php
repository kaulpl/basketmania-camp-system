<?php
if (!defined('ABSPATH')) exit;

/** Wersja 0.76 – test end-to-end integracji KSeF TEST przy każdej fakturze. */
final class BCS_Release_076 {
    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'replace_ksef_page'], 10000);
        add_action('wp_ajax_bcs_ksef_e2e_test_076', [__CLASS__, 'ajax_test']);
    }

    public static function replace_ksef_page(): void {
        $hook = get_plugin_page_hookname('bcs-ksef', 'bcs-dashboard');
        if (!$hook) return;
        remove_action($hook, ['BCS_KSeF_Admin', 'page']);
        remove_action($hook, ['BCS_Release_075', 'page']);
        remove_action($hook, [__CLASS__, 'page']);
        add_action($hook, [__CLASS__, 'page']);
    }

    private static function invoice(int $id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT i.*, r.invoice_buyer_name, r.parent_first_name, r.parent_last_name, '
            .'o.name organizer_name, o.ksef_enabled, o.ksef_token_ciphertext, o.ksef_token_nonce '
            .'FROM '.BCS_DB::table('invoices').' i '
            .'JOIN '.BCS_DB::table('registrations').' r ON r.id=i.registration_id '
            .'JOIN '.BCS_DB::table('organizers').' o ON o.id=i.organizer_id WHERE i.id=%d',
            $id
        )) ?: null;
    }

    public static function ajax_test(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Brak uprawnień.'], 403);
        $id = absint($_POST['invoice_id'] ?? 0);
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        if (!$id || !wp_verify_nonce($nonce, 'bcs_ksef_invoice_075_'.$id)) wp_send_json_error(['message'=>'Sesja wygasła. Odśwież stronę.'], 403);
        if (!self::invoice($id)) wp_send_json_error(['message'=>'Nie znaleziono faktury.'], 404);

        $result = BCS_KSeF_Test_Service::run($id);
        if (!empty($result['success'])) wp_send_json_success($result);
        wp_send_json_error($result, 422);
    }

    public static function page(): void {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $organizers = $wpdb->get_results('SELECT * FROM '.BCS_DB::table('organizers').' ORDER BY name');
        $invoices = $wpdb->get_results(
            'SELECT i.*, r.invoice_buyer_name, r.parent_first_name, r.parent_last_name, o.name organizer_name, '
            .'o.ksef_enabled, o.ksef_token_ciphertext, o.ksef_token_nonce '
            .'FROM '.BCS_DB::table('invoices').' i '
            .'JOIN '.BCS_DB::table('registrations').' r ON r.id=i.registration_id '
            .'JOIN '.BCS_DB::table('organizers').' o ON o.id=i.organizer_id '
            .'ORDER BY i.id DESC LIMIT 150'
        );

        echo '<div class="wrap bcs-admin bcs-ksef-page-076"><div class="bcs-page-head"><div><h1>KSeF TEST</h1><p>Generowanie, wysyłka, pobranie oraz pełny test integracji z testowym API KSeF 2.0.</p></div><span class="bcs-version-label">API '.esc_html(BCS_KSeF_Config::API_VERSION).'</span></div>';
        echo '<div class="notice notice-warning inline"><p><strong>Test E2E KSeF:</strong> przycisk przy fakturze sprawdza cały rzeczywisty przepływ na środowisku TEST: przygotowanie FA(3) → uwierzytelnienie → wysyłkę → nadanie numeru KSeF → pobranie XML z KSeF → porównanie z dokumentem wysłanym. Środowisko TEST nie wywołuje skutków prawnych, ale używamy wyłącznie zanonimizowanych danych testowych.</p></div>';

        echo '<div class="bcs-list-grid">';
        foreach ($organizers as $organizer) {
            $configured = BCS_KSeF_Secret::configured($organizer);
            $enabled = (int)($organizer->ksef_enabled ?? 0) === 1;
            echo '<article class="bcs-list-card"><div class="bcs-card-top"><div><span class="bcs-badge '.($enabled && $configured ? 'status-open' : 'status-draft').'">'.($enabled && $configured ? 'Gotowy do testów' : 'Wymaga konfiguracji').'</span><h2>'.esc_html((string)$organizer->name).'</h2></div><span class="bcs-id">#'.(int)$organizer->id.'</span></div>';
            echo '<dl><div><dt>Środowisko</dt><dd>TEST</dd></div><div><dt>Token</dt><dd>'.($configured ? 'Zapisany i zaszyfrowany' : 'Brak').'</dd></div><div><dt>NIP kontekstu</dt><dd>'.esc_html((string)($organizer->ksef_context_nip ?: '—')).'</dd></div><div><dt>Ostatni test połączenia</dt><dd>'.esc_html(!empty($organizer->ksef_last_test_at) ? BCS_Utils::format_datetime((string)$organizer->ksef_last_test_at) : '—').'</dd></div></dl>';
            echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=bcs-organizers&edit='.(int)$organizer->id)).'">Konfiguruj</a></article>';
        }
        if (!$organizers) echo '<div class="bcs-empty">Brak Organizatorów.</div>';
        echo '</div>';

        echo '<section class="bcs-panel"><div class="bcs-panel-head"><div><h2>Faktury KSeF</h2><p>Najpierw możemy testować proces tutaj. Po potwierdzeniu stabilności ten sam mechanizm będzie można przenieść do modułu Faktury.</p></div></div><div class="bcs-table-wrap"><table class="widefat striped"><thead><tr><th>Faktura</th><th>Organizator / nabywca</th><th>Brutto</th><th>Status KSeF</th><th>Numer KSeF</th><th>Test integracji</th><th>Działania</th></tr></thead><tbody>';
        foreach ($invoices as $invoice) self::row($invoice);
        if (!$invoices) echo '<tr><td colspan="7">Brak wygenerowanych faktur.</td></tr>';
        echo '</tbody></table></div></section></div>';
        self::assets();
    }

    private static function row(object $invoice): void {
        $id = (int)$invoice->id;
        $nonce = wp_create_nonce('bcs_ksef_invoice_075_'.$id);
        $status = (string)($invoice->ksef_status ?: 'not_sent');
        $buyer = trim((string)($invoice->invoice_buyer_name ?: ($invoice->parent_first_name.' '.$invoice->parent_last_name)));
        $localXml = !empty($invoice->ksef_xml_path) && is_file((string)$invoice->ksef_xml_path)
            ? wp_nonce_url(admin_url('admin-post.php?action=bcs_ksef_download_xml_072&invoice_id='.$id), 'bcs_ksef_download_'.$id) : '';
        $preview = !empty($invoice->ksef_number)
            ? wp_nonce_url(admin_url('admin-post.php?action=bcs_ksef_preview_075&invoice_id='.$id), 'bcs_ksef_preview_075_'.$id) : '';
        $configured = !empty($invoice->ksef_token_ciphertext) && !empty($invoice->ksef_token_nonce) && (int)$invoice->ksef_enabled === 1;

        echo '<tr data-ksef-invoice="'.$id.'"><td><strong>'.esc_html((string)$invoice->invoice_number).'</strong><br><small>'.esc_html((string)$invoice->issue_date).'</small></td>';
        echo '<td><strong>'.esc_html((string)$invoice->organizer_name).'</strong><br><small>'.esc_html($buyer).'</small></td>';
        echo '<td>'.esc_html(number_format((float)$invoice->gross_amount, 2, ',', ' ').' zł').'</td>';
        echo '<td><span class="bcs-ksef-status-075 bcs-ksef-status-'.esc_attr($status).'">'.esc_html(self::status_label($status)).'</span>'.(!empty($invoice->ksef_status_description) ? '<br><small>'.esc_html((string)$invoice->ksef_status_description).'</small>' : '').'</td>';
        echo '<td class="bcs-ksef-number-075">'.esc_html((string)($invoice->ksef_number ?: '—')).'</td>';
        self::test_cell($invoice, $configured, $nonce);
        echo '<td><div class="bcs-ksef-actions-075">';
        if ($status !== 'accepted') echo '<button type="button" class="button bcs-ksef-action-075" data-action="bcs_ksef_generate_075" data-label="Generowanie…" data-invoice="'.$id.'" data-nonce="'.esc_attr($nonce).'">Generuj fakturę KSeF</button>';
        if ($localXml) echo '<a class="button bcs-ksef-local-xml-075" href="'.esc_url($localXml).'">Pobierz XML</a>';
        if ($configured && !in_array($status, ['accepted','processing','sending'], true) && $localXml) echo '<button type="button" class="button button-primary bcs-ksef-action-075" data-action="bcs_ksef_send_075" data-label="Wysyłanie…" data-invoice="'.$id.'" data-nonce="'.esc_attr($nonce).'">Wyślij do KSeF TEST</button>';
        if ($configured && in_array($status, ['processing','sending'], true)) echo '<button type="button" class="button bcs-ksef-action-075" data-action="bcs_ksef_refresh_075" data-label="Sprawdzanie…" data-invoice="'.$id.'" data-nonce="'.esc_attr($nonce).'">Odśwież status</button>';
        if ($preview) echo '<a class="button button-primary bcs-ksef-preview-075" target="_blank" href="'.esc_url($preview).'">Podgląd z KSeF</a>';
        if (!$configured) echo '<small class="bcs-ksef-warning-075">Skonfiguruj i włącz token Organizatora.</small>';
        echo '</div><div class="bcs-ksef-result-075"></div></td></tr>';
    }

    private static function test_cell(object $invoice, bool $configured, string $nonce): void {
        $testStatus = (string)($invoice->ksef_test_status ?? '');
        $labels = ['passed'=>'TEST OK', 'pending'=>'Test w toku', 'failed'=>'Błąd testu'];
        $label = $labels[$testStatus] ?? 'Nie testowano';
        $class = $testStatus !== '' ? $testStatus : 'none';
        $button = $testStatus === 'pending' ? 'Dokończ test' : ($testStatus === 'passed' ? 'Sprawdź ponownie' : 'Uruchom test E2E');
        echo '<td class="bcs-ksef-test-cell-076"><span class="bcs-ksef-test-badge-076 is-'.esc_attr($class).'">'.esc_html($label).'</span>';
        if (!empty($invoice->ksef_tested_at)) echo '<small class="bcs-ksef-test-date-076">'.esc_html(BCS_Utils::format_datetime((string)$invoice->ksef_tested_at)).'</small>';
        if (!empty($invoice->ksef_test_message)) echo '<div class="bcs-ksef-test-message-076">'.esc_html((string)$invoice->ksef_test_message).'</div>';
        self::test_details((string)($invoice->ksef_test_details ?? ''));
        if ($configured) {
            echo '<button type="button" class="button '.($testStatus === 'passed' ? '' : 'button-primary').' bcs-ksef-test-076" data-invoice="'.(int)$invoice->id.'" data-nonce="'.esc_attr($nonce).'">'.esc_html($button).'</button>';
        } else {
            echo '<small class="bcs-ksef-warning-075">Najpierw skonfiguruj KSeF Organizatora.</small>';
        }
        echo '<div class="bcs-ksef-test-result-076"></div></td>';
    }

    private static function test_details(string $json): void {
        if ($json === '') return;
        $steps = json_decode($json, true);
        if (!is_array($steps) || !$steps) return;
        echo '<details class="bcs-ksef-test-details-076"><summary>Etapy testu</summary><ol>';
        foreach ($steps as $step) {
            if (!is_array($step)) continue;
            $status = (string)($step['status'] ?? '');
            $symbol = $status === 'passed' ? '✓' : ($status === 'failed' ? '✕' : ($status === 'running' ? '…' : '•'));
            echo '<li class="is-'.esc_attr($status).'"><strong>'.esc_html($symbol.' '.(string)($step['label'] ?? 'Etap')).'</strong><br><span>'.esc_html((string)($step['message'] ?? '')).'</span></li>';
        }
        echo '</ol></details>';
    }

    private static function status_label(string $status): string {
        return [
            'not_sent'=>'Nie wygenerowano', 'xml_ready'=>'Faktura KSeF wygenerowana', 'sending'=>'Wysyłanie',
            'processing'=>'Przetwarzanie przez KSeF', 'accepted'=>'Przyjęto w KSeF TEST',
            'rejected'=>'Odrzucono przez KSeF', 'connection_error'=>'Błąd połączenia',
        ][$status] ?? ($status !== '' ? $status : 'Nie wygenerowano');
    }

    private static function assets(): void {
        ?>
        <style>
        .bcs-ksef-page-076 .bcs-table-wrap{overflow:auto}.bcs-ksef-actions-075{display:flex;flex-wrap:wrap;gap:6px;align-items:center}.bcs-ksef-result-075,.bcs-ksef-test-result-076{margin-top:7px;font-size:12px;font-weight:600}.bcs-ksef-result-075.is-ok,.bcs-ksef-test-result-076.is-ok{color:#166534}.bcs-ksef-result-075.is-error,.bcs-ksef-test-result-076.is-error{color:#b42318}.bcs-ksef-warning-075{display:block;color:#b45309}.bcs-ksef-status-075,.bcs-ksef-test-badge-076{display:inline-block;padding:4px 8px;border-radius:999px;background:#e2e8f0;font-weight:700;font-size:12px}.bcs-ksef-status-accepted,.bcs-ksef-test-badge-076.is-passed{background:#dcfce7;color:#166534}.bcs-ksef-status-processing,.bcs-ksef-status-sending,.bcs-ksef-test-badge-076.is-pending{background:#fef3c7;color:#92400e}.bcs-ksef-status-rejected,.bcs-ksef-status-connection_error,.bcs-ksef-test-badge-076.is-failed{background:#fee2e2;color:#991b1b}.bcs-ksef-test-cell-076{min-width:270px}.bcs-ksef-test-date-076{display:block;color:#64748b;margin:4px 0}.bcs-ksef-test-message-076{font-size:12px;margin:6px 0;max-width:330px}.bcs-ksef-test-details-076{margin:7px 0;font-size:12px}.bcs-ksef-test-details-076 ol{margin:7px 0 9px 19px}.bcs-ksef-test-details-076 li{margin-bottom:5px}.bcs-ksef-test-details-076 li.is-passed{color:#166534}.bcs-ksef-test-details-076 li.is-failed{color:#991b1b}
        </style>
        <script>
        document.addEventListener('click', async (event) => {
            const button = event.target.closest('.bcs-ksef-action-075');
            if (!button) return;
            const row = button.closest('tr'); const result = row.querySelector('.bcs-ksef-result-075'); const original = button.textContent;
            button.disabled=true; button.textContent=button.dataset.label||'Przetwarzanie…'; result.className='bcs-ksef-result-075'; result.textContent='';
            const data=new URLSearchParams({action:button.dataset.action,invoice_id:button.dataset.invoice,nonce:button.dataset.nonce});
            try{const response=await fetch(window.ajaxurl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:data.toString()});const json=await response.json();const ok=Boolean(json.success);const message=json.data?.message||(ok?'Wykonano.':'Operacja nie powiodła się.');result.classList.add(ok?'is-ok':'is-error');result.textContent=message;if(typeof window.bcsNotify==='function')window.bcsNotify(message,ok);if(ok)setTimeout(()=>window.location.reload(),1300);}catch(error){result.classList.add('is-error');result.textContent='Nie udało się odczytać odpowiedzi serwera.';if(typeof window.bcsNotify==='function')window.bcsNotify(result.textContent,false);}finally{button.disabled=false;button.textContent=original;}
        });

        document.addEventListener('click', async (event) => {
            const button=event.target.closest('.bcs-ksef-test-076'); if(!button)return;
            if(!window.confirm('Uruchomić pełny test integracji? Jeśli faktura nie została jeszcze wysłana, zostanie rzeczywiście przekazana do środowiska KSeF TEST.'))return;
            const cell=button.closest('.bcs-ksef-test-cell-076'); const result=cell.querySelector('.bcs-ksef-test-result-076'); const original=button.textContent;
            button.disabled=true; button.textContent='Testowanie…'; result.className='bcs-ksef-test-result-076'; result.textContent='Sprawdzanie pełnego przepływu KSeF…';
            const run=async()=>{const data=new URLSearchParams({action:'bcs_ksef_e2e_test_076',invoice_id:button.dataset.invoice,nonce:button.dataset.nonce});const response=await fetch(window.ajaxurl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:data.toString()});return response.json();};
            try{
                let json=await run(); let retries=0;
                while(json.success && json.data?.test_status==='pending' && retries<6){result.textContent=json.data?.message||'KSeF nadal przetwarza dokument…';await new Promise(resolve=>setTimeout(resolve,2500));json=await run();retries++;}
                const ok=Boolean(json.success)&&json.data?.test_status==='passed'; const pending=Boolean(json.success)&&json.data?.test_status==='pending'; const message=json.data?.message||(ok?'TEST OK.':pending?'Test nadal trwa.':'Test nie powiódł się.');
                result.classList.add(ok?'is-ok':pending?'':'is-error');result.textContent=message;if(typeof window.bcsNotify==='function')window.bcsNotify(message,ok||pending);setTimeout(()=>window.location.reload(),ok?1200:pending?2200:1800);
            }catch(error){result.classList.add('is-error');result.textContent='Nie udało się wykonać testu KSeF.';if(typeof window.bcsNotify==='function')window.bcsNotify(result.textContent,false);button.disabled=false;button.textContent=original;}
        });
        </script>
        <?php
    }
}
