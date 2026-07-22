<?php
if (!defined('ABSPATH')) exit;

final class BCS_Invoice_Batches {
    private const CRON_HOOK = 'bcs_monthly_invoice_dispatch';
    private const SETTINGS_KEY = 'bcs_invoice_batch_settings';
    private const LAST_SENT_KEY = 'bcs_invoice_batch_last_sent';

    public static function init(): void {
        add_action('init', [self::class, 'ensure_schedule']);
        add_action(self::CRON_HOOK, [self::class, 'scheduled_dispatch']);
        add_action('admin_post_bcs_invoice_batch_download', [self::class, 'download_selected']);
        add_action('admin_post_bcs_invoice_batch_send', [self::class, 'send_selected']);
        add_action('admin_post_bcs_invoice_batch_send_previous_month', [self::class, 'send_previous_month_now']);
        add_action('admin_post_bcs_invoice_batch_save_settings', [self::class, 'save_settings']);
        add_action('admin_footer', [self::class, 'invoice_page_enhancements']);
    }

    public static function ensure_schedule(): void {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    private static function settings(): array {
        $settings = get_option(self::SETTINGS_KEY, []);
        return [
            'day' => max(1, min(28, (int)($settings['day'] ?? 10))),
            'enabled' => !empty($settings['enabled']),
        ];
    }

    public static function invoice_page_enhancements(): void {
        if (!current_user_can('manage_options')) return;
        if (sanitize_key(wp_unslash($_GET['page'] ?? '')) !== 'bcs-invoices') return;
        $settings = self::settings();
        $config = [
            'downloadAction' => 'bcs_invoice_batch_download',
            'sendAction' => 'bcs_invoice_batch_send',
            'downloadNonce' => wp_create_nonce('bcs_invoice_batch_download'),
            'sendNonce' => wp_create_nonce('bcs_invoice_batch_send'),
            'postUrl' => admin_url('admin-post.php'),
        ];
        ?>
        <style>
            .bcs-invoice-batch-tools{margin:16px 0;padding:16px;background:#fff;border:1px solid #c3c4c7;border-radius:8px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
            .bcs-invoice-batch-settings{margin:16px 0;padding:16px;background:#fff;border:1px solid #c3c4c7;border-radius:8px;display:flex;gap:12px;align-items:center;flex-wrap:wrap}
            .bcs-invoice-batch-settings label{display:flex;gap:8px;align-items:center}
            .bcs-invoice-select{width:36px;text-align:center}
        </style>
        <script>
        document.addEventListener('DOMContentLoaded', function(){
            const table = document.querySelector('.bcs-invoices-page table.bcs-table');
            if (!table) return;
            const config = <?php echo wp_json_encode($config); ?>;
            const headRow = table.querySelector('thead tr');
            const head = document.createElement('th');
            head.className = 'bcs-invoice-select';
            head.innerHTML = '<input type="checkbox" id="bcs-invoice-select-all" aria-label="Zaznacz wszystkie">';
            headRow.insertBefore(head, headRow.firstChild);
            table.querySelectorAll('tbody tr').forEach(function(row){
                const idCell = row.querySelector('td');
                if (!idCell) return;
                const match = idCell.textContent.match(/#(\d+)/);
                const cell = document.createElement('td');
                cell.className = 'bcs-invoice-select';
                if (match) cell.innerHTML = '<input type="checkbox" class="bcs-invoice-check" value="'+match[1]+'" aria-label="Zaznacz fakturę #'+match[1]+'">';
                row.insertBefore(cell, row.firstChild);
            });
            const panel = table.closest('.bcs-panel');
            const tools = document.createElement('div');
            tools.className = 'bcs-invoice-batch-tools';
            tools.innerHTML = '<strong>Zbiorcze operacje:</strong><button type="button" class="button" data-bcs-batch="download">Pobierz zaznaczone w jednym PDF</button><button type="button" class="button button-primary" data-bcs-batch="send">Wyślij zaznaczone do organizatora</button><span class="description">Faktury wybrane dla różnych organizatorów zostaną pogrupowane i wysłane osobno.</span>';
            panel.insertBefore(tools, table.closest('.bcs-table-wrap'));
            document.getElementById('bcs-invoice-select-all').addEventListener('change', function(){
                document.querySelectorAll('.bcs-invoice-check').forEach(cb => cb.checked = this.checked);
            });
            function submitBatch(mode){
                const ids = Array.from(document.querySelectorAll('.bcs-invoice-check:checked')).map(cb => cb.value);
                if (!ids.length) { alert('Zaznacz co najmniej jedną fakturę.'); return; }
                if (mode === 'send' && !confirm('System zweryfikuje '+ids.length+' faktur, pogrupuje je według organizatora i wyśle jako zbiorcze pliki PDF. Kontynuować?')) return;
                const form = document.createElement('form');
                form.method = 'post'; form.action = config.postUrl; form.target = mode === 'download' ? '_blank' : '_self';
                const fields = {action: mode === 'download' ? config.downloadAction : config.sendAction, _wpnonce: mode === 'download' ? config.downloadNonce : config.sendNonce};
                Object.keys(fields).forEach(function(name){ const input=document.createElement('input'); input.type='hidden'; input.name=name; input.value=fields[name]; form.appendChild(input); });
                ids.forEach(function(id){ const input=document.createElement('input'); input.type='hidden'; input.name='invoice_ids[]'; input.value=id; form.appendChild(input); });
                document.body.appendChild(form); form.submit(); form.remove();
            }
            tools.querySelector('[data-bcs-batch="download"]').addEventListener('click', () => submitBatch('download'));
            tools.querySelector('[data-bcs-batch="send"]').addEventListener('click', () => submitBatch('send'));
        });
        </script>
        <div class="bcs-invoice-batch-settings" style="display:none" id="bcs-invoice-batch-settings-template">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
                <input type="hidden" name="action" value="bcs_invoice_batch_save_settings">
                <?php wp_nonce_field('bcs_invoice_batch_save_settings'); ?>
                <strong>Miesięczna wysyłka faktur:</strong>
                <label><input type="checkbox" name="enabled" value="1" <?php checked($settings['enabled']); ?>> Włącz automatyczną wysyłkę</label>
                <label>Dzień miesiąca <input type="number" min="1" max="28" name="day" value="<?php echo esc_attr((string)$settings['day']); ?>" style="width:75px"></label>
                <button class="button">Zapisz ustawienia</button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('System policzy faktury z poprzedniego miesiąca, utworzy osobny zbiorczy PDF dla każdego organizatora i wyśle wiadomości. Kontynuować?')">
                <input type="hidden" name="action" value="bcs_invoice_batch_send_previous_month">
                <?php wp_nonce_field('bcs_invoice_batch_send_previous_month'); ?>
                <button class="button button-primary">Wyślij faktury za poprzedni miesiąc teraz</button>
            </form>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function(){
            const template=document.getElementById('bcs-invoice-batch-settings-template');
            const page=document.querySelector('.bcs-invoices-page .bcs-page-head');
            if(template&&page){template.style.display='flex';page.insertAdjacentElement('afterend',template);}
        });
        </script>
        <?php
    }

    public static function save_settings(): void {
        self::guard('bcs_invoice_batch_save_settings');
        update_option(self::SETTINGS_KEY, [
            'enabled' => isset($_POST['enabled']) ? 1 : 0,
            'day' => max(1, min(28, absint($_POST['day'] ?? 10))),
        ]);
        self::redirect(['batch_saved' => 1]);
    }

    public static function download_selected(): void {
        self::guard('bcs_invoice_batch_download');
        $ids = self::selected_ids();
        if (!$ids) wp_die('Nie wybrano faktur.');
        $path = self::build_combined_pdf($ids, 'Zbiorczy plik faktur');
        self::stream_file($path, 'faktury-zbiorcze-' . current_time('Y-m-d-His') . '.pdf');
    }

    public static function send_selected(): void {
        self::guard('bcs_invoice_batch_send');
        $ids = self::selected_ids();
        if (!$ids) self::redirect(['batch_error' => 'Nie wybrano faktur.']);
        $result = self::send_grouped($ids, 'Wybrane faktury', 'W załączeniu przesyłamy zbiorczy plik PDF zawierający wybrane faktury.');
        self::redirect(['batch_sent' => $result['sent'], 'batch_invoices' => $result['invoices'], 'batch_failed' => $result['failed']]);
    }

    public static function send_previous_month_now(): void {
        self::guard('bcs_invoice_batch_send_previous_month');
        $result = self::dispatch_previous_month(false);
        self::redirect(['batch_sent' => $result['sent'], 'batch_invoices' => $result['invoices'], 'batch_failed' => $result['failed']]);
    }

    public static function scheduled_dispatch(): void {
        $settings = self::settings();
        if (!$settings['enabled'] || (int)current_time('j') !== $settings['day']) return;
        $month_key = wp_date('Y-m', strtotime('first day of previous month', current_time('timestamp')));
        if ((string)get_option(self::LAST_SENT_KEY, '') === $month_key) return;
        $result = self::dispatch_previous_month(true);
        if ($result['failed'] === 0) update_option(self::LAST_SENT_KEY, $month_key);
    }

    private static function dispatch_previous_month(bool $automatic): array {
        global $wpdb;
        $start = wp_date('Y-m-01', strtotime('first day of previous month', current_time('timestamp')));
        $end = wp_date('Y-m-01', strtotime('first day of this month', current_time('timestamp')));
        $ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM " . BCS_DB::table('invoices') . " WHERE issue_date >= %s AND issue_date < %s ORDER BY organizer_id, issue_date, id",
            $start, $end
        )));
        if (!$ids) return ['sent'=>0,'failed'=>0,'invoices'=>0];
        $month_label = wp_date('F Y', strtotime($start));
        $result = self::send_grouped($ids, 'Faktury za ' . $month_label, 'W załączeniu przesyłamy zbiorczy plik PDF zawierający faktury wystawione w poprzednim miesiącu.');
        BCS_Utils::log('monthly_invoice_batch_dispatch', ['automatic'=>$automatic,'period_start'=>$start,'period_end'=>$end,'sent'=>$result['sent'],'failed'=>$result['failed'],'invoices'=>$result['invoices']]);
        return $result;
    }

    private static function send_grouped(array $ids, string $subject_prefix, string $body): array {
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = "SELECT i.id,i.organizer_id,o.name organizer_name,o.email organizer_email FROM " . BCS_DB::table('invoices') . " i LEFT JOIN " . BCS_DB::table('organizers') . " o ON o.id=i.organizer_id WHERE i.id IN ($placeholders) ORDER BY i.organizer_id,i.id";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $ids));
        $groups = [];
        foreach ($rows as $row) $groups[(int)$row->organizer_id][] = $row;
        $sent = 0; $failed = 0; $invoice_count = 0;
        foreach ($groups as $organizer_id => $items) {
            $group_ids = array_map(static fn($row) => (int)$row->id, $items);
            $invoice_count += count($group_ids);
            $email = sanitize_email((string)($items[0]->organizer_email ?? ''));
            if (!is_email($email)) { $failed++; continue; }
            $path = self::build_combined_pdf($group_ids, $subject_prefix . ' – ' . (string)$items[0]->organizer_name);
            $subject = $subject_prefix . ' – ' . (string)$items[0]->organizer_name;
            $ok = BCS_Mailer::send($email, $subject, '<p>' . esc_html($body) . '</p><p>Liczba faktur: <strong>' . count($group_ids) . '</strong>.</p>', [], [$path]);
            @unlink($path);
            if ($ok) $sent++; else $failed++;
        }
        return ['sent'=>$sent,'failed'=>$failed,'invoices'=>$invoice_count];
    }

    private static function build_combined_pdf(array $ids, string $title): string {
        global $wpdb;
        if (!BCS_PDF::available()) wp_die('Silnik PDF nie jest dostępny.');
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) wp_die('Brak faktur do wygenerowania.');
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = "SELECT i.*,r.parent_first_name,r.parent_last_name,r.parent_address,r.parent_postal_code,r.parent_city,r.parent_street,r.parent_house_number,r.paid_amount,r.parent_email,r.parent_phone,c.name camp_name,c.start_date,c.end_date,c.location,c.organizer_id,o.name organizer_name,o.address organizer_address,o.nip organizer_nip,o.regon organizer_regon,o.email organizer_email,o.phone organizer_phone,o.bank_account FROM " . BCS_DB::table('invoices') . " i JOIN " . BCS_DB::table('registrations') . " r ON r.id=i.registration_id JOIN " . BCS_DB::table('camps') . " c ON c.id=r.camp_id LEFT JOIN " . BCS_DB::table('organizers') . " o ON o.id=i.organizer_id WHERE i.id IN ($placeholders) ORDER BY i.organizer_id,i.issue_date,i.id";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $ids));
        if (!$rows) wp_die('Nie znaleziono faktur.');
        $settings = get_option('bcs_settings', []);
        $pages = [];
        foreach ($rows as $row) {
            $money = static fn(float $value): string => number_format($value, 2, ',', ' ') . ' PLN';
            $address = BCS_Utils::registration_address($row);
            $vars = [
                '{{LOGO_DATA_URI}}' => self::logo_data_uri(),
                '{{INVOICE_NUMBER}}' => (string)$row->invoice_number,
                '{{ORGANIZER_NAME}}' => esc_html((string)$row->organizer_name),
                '{{ORGANIZER_ADDRESS}}' => nl2br(esc_html((string)$row->organizer_address)),
                '{{ORGANIZER_NIP}}' => esc_html((string)$row->organizer_nip),
                '{{ORGANIZER_EMAIL}}' => esc_html((string)$row->organizer_email),
                '{{ORGANIZER_PHONE}}' => esc_html((string)$row->organizer_phone),
                '{{BUYER_NAME}}' => esc_html(trim((string)$row->parent_first_name . ' ' . (string)$row->parent_last_name)),
                '{{BUYER_ADDRESS}}' => nl2br(esc_html($address)),
                '{{ISSUE_PLACE}}' => esc_html((string)$row->location ?: 'Pelplin'),
                '{{ISSUE_DATE}}' => wp_date('d-m-Y', strtotime((string)$row->issue_date)),
                '{{SALE_DATE}}' => wp_date('d-m-Y', strtotime((string)$row->issue_date)),
                '{{PAYMENT_DATE}}' => wp_date('d-m-Y', strtotime((string)$row->issue_date)),
                '{{CAMP_NAME}}' => esc_html((string)$row->camp_name),
                '{{CAMP_DATES}}' => esc_html((string)$row->start_date . ' – ' . (string)$row->end_date),
                '{{NET_AMOUNT}}' => $money((float)$row->net_amount),
                '{{VAT_LABEL}}' => (float)$row->vat_rate > 0 ? esc_html((string)$row->vat_rate . '% / ' . $money((float)$row->vat_amount)) : 'zw.',
                '{{GROSS_AMOUNT}}' => $money((float)$row->gross_amount),
                '{{AMOUNT_DUE}}' => '0,00 PLN',
                '{{BANK_ACCOUNT}}' => esc_html((string)$row->bank_account),
                '{{PAYMENT_METHOD}}' => 'przelew',
                '{{EXEMPTION_NOTE}}' => (!(float)$row->vat_rate && !empty($settings['invoice_exemption_basis'])) ? '<div class="invoice-note">Podstawa zwolnienia: ' . esc_html((string)$settings['invoice_exemption_basis']) . '</div>' : '',
            ];
            $pages[] = BCS_Template_Engine::render(BCS_Template_Engine::get('documents', 'invoice'), $vars);
        }
        $body = implode('<div style="page-break-after:always"></div>', $pages);
        $html = BCS_Document_Engine::html_document($title, $body);
        $upload = wp_upload_dir();
        if (!empty($upload['error'])) wp_die(esc_html((string)$upload['error']));
        $path = trailingslashit($upload['basedir']) . 'bcs-invoices-batch-' . wp_generate_uuid4() . '.pdf';
        if (!BCS_PDF::generate($html, $path, $title)) wp_die('Nie udało się utworzyć zbiorczego pliku PDF.');
        return $path;
    }

    private static function logo_data_uri(): string {
        $path = BCS_DIR . 'assets/images/logo-basketmania-camp-color.png';
        return file_exists($path) ? 'data:image/png;base64,' . base64_encode((string)file_get_contents($path)) : '';
    }

    private static function selected_ids(): array {
        $raw = isset($_POST['invoice_ids']) && is_array($_POST['invoice_ids']) ? wp_unslash($_POST['invoice_ids']) : [];
        return array_values(array_unique(array_filter(array_map('absint', $raw))));
    }

    private static function stream_file(string $path, string $filename): void {
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        @unlink($path);
        exit;
    }

    private static function guard(string $action): void {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        check_admin_referer($action);
    }

    private static function redirect(array $args): void {
        wp_safe_redirect(add_query_arg(array_merge(['page'=>'bcs-invoices'], $args), admin_url('admin.php')));
        exit;
    }
}
