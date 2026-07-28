<?php
if (!defined('ABSPATH')) exit;

/** Wersja 0.75 – operacyjny panel faktur KSeF TEST. */
final class BCS_Release_075 {
    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'replace_ksef_page'], 9999);
        add_action('wp_ajax_bcs_ksef_generate_075', [__CLASS__, 'ajax_generate']);
        add_action('wp_ajax_bcs_ksef_send_075', [__CLASS__, 'ajax_send']);
        add_action('wp_ajax_bcs_ksef_refresh_075', [__CLASS__, 'ajax_refresh']);
        add_action('admin_post_bcs_ksef_preview_075', [__CLASS__, 'preview']);
    }

    public static function replace_ksef_page(): void {
        $hook = get_plugin_page_hookname('bcs-ksef', 'bcs-dashboard');
        if (!$hook) return;
        remove_action($hook, ['BCS_KSeF_Admin', 'page']);
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

    private static function authorize_invoice(): object {
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Brak uprawnień.'], 403);
        $id = absint($_POST['invoice_id'] ?? 0);
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        if (!$id || !wp_verify_nonce($nonce, 'bcs_ksef_invoice_075_'.$id)) wp_send_json_error(['message'=>'Sesja wygasła. Odśwież stronę.'], 403);
        $invoice = self::invoice($id);
        if (!$invoice) wp_send_json_error(['message'=>'Nie znaleziono faktury.'], 404);
        return $invoice;
    }

    public static function ajax_generate(): void {
        $invoice = self::authorize_invoice();
        if ((string)$invoice->ksef_status === 'accepted') wp_send_json_error(['message'=>'Przyjętej faktury KSeF nie można wygenerować ponownie.'], 409);
        $result = BCS_KSeF_FA3::prepare_and_save((int)$invoice->id);
        if (!empty($result['success'])) {
            $download = wp_nonce_url(admin_url('admin-post.php?action=bcs_ksef_download_xml_072&invoice_id='.(int)$invoice->id), 'bcs_ksef_download_'.(int)$invoice->id);
            wp_send_json_success(['message'=>'Wygenerowano fakturę KSeF w strukturze XML FA(3).', 'status'=>'xml_ready', 'download_url'=>$download]);
        }
        $message = (string)($result['message'] ?? 'Nie udało się wygenerować faktury KSeF.');
        if (!empty($result['errors'])) $message .= ' '.implode(' ', array_map('strval', (array)$result['errors']));
        wp_send_json_error(['message'=>$message], 422);
    }

    public static function ajax_send(): void {
        $invoice = self::authorize_invoice();
        $result = BCS_KSeF_Service::send((int)$invoice->id);
        if (!empty($result['success'])) wp_send_json_success($result);
        wp_send_json_error($result, 422);
    }

    public static function ajax_refresh(): void {
        $invoice = self::authorize_invoice();
        $result = BCS_KSeF_Service::refresh_status((int)$invoice->id);
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

        echo '<div class="wrap bcs-admin bcs-ksef-page-075"><div class="bcs-page-head"><div><h1>KSeF TEST</h1><p>Generowanie XML FA(3), rzeczywista wysyłka do środowiska TEST i podgląd dokumentu pobranego z KSeF.</p></div><span class="bcs-version-label">API '.esc_html(BCS_KSeF_Config::API_VERSION).'</span></div>';
        echo '<div class="notice notice-warning inline"><p><strong>Środowisko testowe:</strong> operacja „Wyślij do KSeF TEST” wykonuje prawdziwe połączenie z testowym API Ministerstwa Finansów, ale nie wywołuje skutków prawnych. Przy aktywnej anonimizacji do XML trafiają dane testowe. Podgląd wymaga uprawnienia tokenu <code>InvoiceRead</code>, a wysyłka <code>InvoiceWrite</code>.</p></div>';

        echo '<div class="bcs-list-grid">';
        foreach ($organizers as $organizer) {
            $configured = BCS_KSeF_Secret::configured($organizer);
            $enabled = (int)($organizer->ksef_enabled ?? 0) === 1;
            echo '<article class="bcs-list-card"><div class="bcs-card-top"><div><span class="bcs-badge '.($enabled && $configured ? 'status-open' : 'status-draft').'">'.($enabled && $configured ? 'Gotowy do testów' : 'Wymaga konfiguracji').'</span><h2>'.esc_html((string)$organizer->name).'</h2></div><span class="bcs-id">#'.(int)$organizer->id.'</span></div>';
            echo '<dl><div><dt>Środowisko</dt><dd>TEST</dd></div><div><dt>Token</dt><dd>'.($configured ? 'Zapisany i zaszyfrowany' : 'Brak').'</dd></div><div><dt>NIP kontekstu</dt><dd>'.esc_html((string)($organizer->ksef_context_nip ?: '—')).'</dd></div><div><dt>Ostatni test</dt><dd>'.esc_html(!empty($organizer->ksef_last_test_at) ? BCS_Utils::format_datetime((string)$organizer->ksef_last_test_at) : '—').'</dd></div></dl>';
            echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=bcs-organizers&edit='.(int)$organizer->id)).'">Konfiguruj</a></article>';
        }
        if (!$organizers) echo '<div class="bcs-empty">Brak Organizatorów.</div>';
        echo '</div>';

        echo '<section class="bcs-panel"><div class="bcs-panel-head"><div><h2>Faktury KSeF</h2><p>Każdy etap jest wykonywany świadomie osobnym przyciskiem: generowanie → wysyłka → podgląd z KSeF.</p></div></div><div class="bcs-table-wrap"><table class="widefat striped"><thead><tr><th>Faktura</th><th>Organizator / nabywca</th><th>Brutto</th><th>Status KSeF</th><th>Numer KSeF</th><th>Działania</th></tr></thead><tbody>';
        foreach ($invoices as $invoice) self::row($invoice);
        if (!$invoices) echo '<tr><td colspan="6">Brak wygenerowanych faktur.</td></tr>';
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
        echo '<td class="bcs-ksef-number-075">'.esc_html((string)($invoice->ksef_number ?: '—')).'</td><td><div class="bcs-ksef-actions-075">';
        if ($status !== 'accepted') echo '<button type="button" class="button bcs-ksef-action-075" data-action="bcs_ksef_generate_075" data-label="Generowanie…" data-invoice="'.$id.'" data-nonce="'.esc_attr($nonce).'">Generuj fakturę KSeF</button>';
        if ($localXml) echo '<a class="button bcs-ksef-local-xml-075" href="'.esc_url($localXml).'">Pobierz XML</a>';
        if ($configured && !in_array($status, ['accepted','processing','sending'], true) && $localXml) echo '<button type="button" class="button button-primary bcs-ksef-action-075" data-action="bcs_ksef_send_075" data-label="Wysyłanie…" data-invoice="'.$id.'" data-nonce="'.esc_attr($nonce).'">Wyślij do KSeF TEST</button>';
        if ($configured && in_array($status, ['processing','sending'], true)) echo '<button type="button" class="button bcs-ksef-action-075" data-action="bcs_ksef_refresh_075" data-label="Sprawdzanie…" data-invoice="'.$id.'" data-nonce="'.esc_attr($nonce).'">Odśwież status</button>';
        if ($preview) echo '<a class="button button-primary bcs-ksef-preview-075" target="_blank" href="'.esc_url($preview).'">Podgląd z KSeF</a>';
        if (!$configured) echo '<small class="bcs-ksef-warning-075">Skonfiguruj i włącz token Organizatora.</small>';
        echo '</div><div class="bcs-ksef-result-075"></div></td></tr>';
    }

    private static function status_label(string $status): string {
        return [
            'not_sent'=>'Nie wygenerowano', 'xml_ready'=>'Faktura KSeF wygenerowana', 'sending'=>'Wysyłanie',
            'processing'=>'Przetwarzanie przez KSeF', 'accepted'=>'Przyjęto w KSeF TEST',
            'rejected'=>'Odrzucono przez KSeF', 'connection_error'=>'Błąd połączenia',
        ][$status] ?? ($status !== '' ? $status : 'Nie wygenerowano');
    }

    public static function preview(): void {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        $invoiceId = absint($_GET['invoice_id'] ?? 0);
        check_admin_referer('bcs_ksef_preview_075_'.$invoiceId);
        $invoice = self::invoice($invoiceId);
        if (!$invoice) wp_die('Nie znaleziono faktury.');
        $result = BCS_KSeF_Service::fetch_remote_xml($invoiceId);
        if (empty($result['success'])) wp_die(esc_html((string)$result['message']), 'Błąd podglądu KSeF', ['response'=>502]);
        $xml = (string)$result['xml'];
        $summary = self::xml_summary($xml);
        nocache_headers();
        echo '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Podgląd faktury z KSeF TEST</title><style>body{margin:0;background:#f1f5f9;color:#172033;font:15px/1.5 Arial,sans-serif}.wrap{max-width:1050px;margin:32px auto;padding:0 20px}.head,.card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:22px;margin-bottom:18px}.head{border-top:5px solid #f97316}.badge{display:inline-block;padding:5px 10px;border-radius:999px;background:#dcfce7;color:#166534;font-weight:700}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.grid div{padding:12px;background:#f8fafc;border-radius:9px}.grid span{display:block;color:#64748b;font-size:12px}.grid strong{display:block;margin-top:3px}.xml{white-space:pre-wrap;word-break:break-word;font:12px/1.45 monospace;background:#0f172a;color:#e2e8f0;padding:18px;border-radius:10px;max-height:650px;overflow:auto}@media(max-width:700px){.grid{grid-template-columns:1fr}}</style></head><body><div class="wrap"><div class="head"><span class="badge">Pobrano bezpośrednio z KSeF TEST</span><h1>Faktura '.esc_html((string)$invoice->invoice_number).'</h1><p>Numer KSeF: <strong>'.esc_html((string)$invoice->ksef_number).'</strong></p></div><div class="card"><h2>Dane dokumentu</h2><div class="grid">';
        foreach ($summary as $label=>$value) echo '<div><span>'.esc_html($label).'</span><strong>'.esc_html($value ?: '—').'</strong></div>';
        echo '</div></div><div class="card"><h2>Oryginalny XML pobrany z KSeF</h2><pre class="xml">'.esc_html($xml).'</pre></div></div></body></html>';
        exit;
    }

    private static function xml_summary(string $xml): array {
        $values = ['Numer faktury'=>'','Data wystawienia'=>'','Sprzedawca'=>'','NIP sprzedawcy'=>'','Nabywca'=>'','Kwota brutto'=>''];
        $dom = new DOMDocument();
        if (!@$dom->loadXML($xml, LIBXML_NONET)) return $values;
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('fa', BCS_KSeF_Config::FA3_NAMESPACE);
        $map = [
            'Numer faktury'=>'string(/fa:Faktura/fa:Fa/fa:P_2)',
            'Data wystawienia'=>'string(/fa:Faktura/fa:Fa/fa:P_1)',
            'Sprzedawca'=>'string(/fa:Faktura/fa:Podmiot1/fa:DaneIdentyfikacyjne/fa:Nazwa)',
            'NIP sprzedawcy'=>'string(/fa:Faktura/fa:Podmiot1/fa:DaneIdentyfikacyjne/fa:NIP)',
            'Nabywca'=>'string(/fa:Faktura/fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:Nazwa)',
            'Kwota brutto'=>'string(/fa:Faktura/fa:Fa/fa:P_15)',
        ];
        foreach ($map as $label=>$query) $values[$label] = trim((string)$xpath->evaluate($query));
        if ($values['Kwota brutto'] !== '') $values['Kwota brutto'] .= ' zł';
        return $values;
    }

    private static function assets(): void {
        ?>
        <style>
        .bcs-ksef-page-075 .bcs-table-wrap{overflow:auto}.bcs-ksef-actions-075{display:flex;flex-wrap:wrap;gap:6px;align-items:center}.bcs-ksef-result-075{margin-top:7px;font-size:12px;font-weight:600}.bcs-ksef-result-075.is-ok{color:#166534}.bcs-ksef-result-075.is-error{color:#b42318}.bcs-ksef-warning-075{display:block;color:#b45309}.bcs-ksef-status-075{display:inline-block;padding:4px 8px;border-radius:999px;background:#e2e8f0;font-weight:700;font-size:12px}.bcs-ksef-status-accepted{background:#dcfce7;color:#166534}.bcs-ksef-status-processing,.bcs-ksef-status-sending{background:#fef3c7;color:#92400e}.bcs-ksef-status-rejected,.bcs-ksef-status-connection_error{background:#fee2e2;color:#991b1b}
        </style>
        <script>
        document.addEventListener('click', async (event) => {
            const button = event.target.closest('.bcs-ksef-action-075');
            if (!button) return;
            const row = button.closest('tr');
            const result = row.querySelector('.bcs-ksef-result-075');
            const original = button.textContent;
            button.disabled = true; button.textContent = button.dataset.label || 'Przetwarzanie…';
            result.className = 'bcs-ksef-result-075'; result.textContent = '';
            const data = new URLSearchParams({action:button.dataset.action, invoice_id:button.dataset.invoice, nonce:button.dataset.nonce});
            try {
                const response = await fetch(window.ajaxurl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:data.toString()});
                const json = await response.json(); const ok = Boolean(json.success); const message = json.data?.message || (ok ? 'Wykonano.' : 'Operacja nie powiodła się.');
                result.classList.add(ok ? 'is-ok' : 'is-error'); result.textContent = message;
                if (typeof window.bcsNotify === 'function') window.bcsNotify(message, ok);
                if (ok) setTimeout(() => window.location.reload(), 1300);
            } catch (error) {
                result.classList.add('is-error'); result.textContent = 'Nie udało się odczytać odpowiedzi serwera.';
                if (typeof window.bcsNotify === 'function') window.bcsNotify(result.textContent, false);
            } finally { button.disabled = false; button.textContent = original; }
        });
        </script>
        <?php
    }
}
