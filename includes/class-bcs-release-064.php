<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_064 {
    public static function init(): void {
        // Rozszerza istniejące zapytania CRM bez duplikowania całego renderera listy.
        add_filter('query', [__CLASS__, 'filter_action_required_query'], 20);

        // Tokenizowane pobranie faktury z Panelu Rodzica i wiadomości e-mail
        // musi zawsze zapisać datę pobrania, także podczas testu w zalogowanej sesji administratora.
        remove_action('template_redirect', ['BCS_Documents', 'public_download']);
        add_action('template_redirect', [__CLASS__, 'public_document_download'], 0);

        // BCS_Camp_Reports rejestruje swój handler później w plugins_loaded,
        // dlatego podmieniamy go dopiero na admin_init.
        add_action('admin_init', [__CLASS__, 'replace_shirts_report_handler'], 1);

        add_action('admin_head', [__CLASS__, 'admin_head']);
        add_action('admin_footer', [__CLASS__, 'admin_footer'], 99);
    }

    public static function expand_action_required_query(string $query): string {
        if (!str_contains($query, "r.status = 'draft_sent'")) return $query;
        if (!str_contains($query, 'requires_action') && !str_contains($query, 'COUNT(DISTINCT r.id)')) return $query;

        return str_replace(
            "r.status = 'draft_sent'",
            "(r.status = 'draft_sent' OR r.status = 'agreement_parent_signed' OR r.agreement_status = 'parent_signed')",
            $query
        );
    }

    public static function filter_action_required_query(string $query): string {
        return self::expand_action_required_query($query);
    }

    public static function public_document_download(): void {
        if (empty($_GET['bcs_document'])) return;

        $document = sanitize_key(wp_unslash($_GET['document'] ?? ''));
        if ($document !== 'invoice') {
            BCS_Documents::public_download();
            return;
        }

        $registration_id = absint($_GET['registration'] ?? 0);
        $token = sanitize_text_field(wp_unslash($_GET['token'] ?? ''));
        $expected = BCS_Documents::document_token($registration_id, 'invoice');
        if (!$registration_id || $token === '' || !hash_equals($expected, $token)) {
            BCS_Utils::log('document_download_denied', [
                'document'=>'invoice',
                'reason'=>$token === '' ? 'missing_token' : 'invalid_token',
                'ip'=>BCS_Utils::client_ip(),
                'release'=>'0.64',
            ], $registration_id, null);
            wp_die('Nieprawidłowy dostęp.', 'Basketmania Camp', ['response'=>403]);
        }

        global $wpdb;
        $invoice = $wpdb->get_row($wpdb->prepare(
            "SELECT id,registration_id,invoice_number,file_path FROM ".BCS_DB::table('invoices')."
             WHERE registration_id=%d ORDER BY id DESC LIMIT 1",
            $registration_id
        ));
        $path = (string)($invoice->file_path ?? '');
        if (!$invoice || $path === '' || !is_file($path)) {
            wp_die('Dokument nie jest jeszcze dostępny.', 'Basketmania Camp', ['response'=>404]);
        }

        BCS_Invoices::record_parent_download((int)$invoice->id, 'parent_portal_or_email_link_064');
        BCS_Utils::log('document_downloaded', [
            'document'=>'invoice',
            'file'=>basename($path),
            'invoice_id'=>(int)$invoice->id,
            'invoice_number'=>(string)$invoice->invoice_number,
            'ip'=>BCS_Utils::client_ip(),
            'release'=>'0.64',
        ], $registration_id, null);

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="'.sanitize_file_name('faktura-'.str_replace('/', '-', (string)$invoice->invoice_number).'.pdf').'"');
        header('Content-Length: '.filesize($path));
        readfile($path);
        exit;
    }

    public static function replace_shirts_report_handler(): void {
        remove_action('admin_post_bcs_camp_shirts_pdf', [BCS_Camp_Reports::class, 'shirts_pdf']);
        add_action('admin_post_bcs_camp_shirts_pdf', [__CLASS__, 'shirts_pdf']);
    }

    public static function shirts_pdf(): void {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        $camp_id = absint($_GET['camp_id'] ?? 0);
        check_admin_referer('bcs_camp_shirts_pdf_'.$camp_id);

        global $wpdb;
        $camp = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM ".BCS_DB::table('camps')." WHERE id=%d",
            $camp_id
        ));
        if (!$camp) wp_die('Nie znaleziono turnusu.');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT child_first_name,child_last_name,shirt_size,total_amount,paid_amount
             FROM ".BCS_DB::table('registrations')."
             WHERE camp_id=%d
               AND status<>'cancelled'
               AND total_amount>0
               AND paid_amount>=total_amount
             ORDER BY child_last_name,child_first_name",
            $camp_id
        ));

        usort($rows, static function(object $a, object $b): int {
            $size = self::shirt_rank((string)$a->shirt_size) <=> self::shirt_rank((string)$b->shirt_size);
            if ($size !== 0) return $size;
            return strcasecmp(
                trim((string)$a->child_last_name.' '.(string)$a->child_first_name),
                trim((string)$b->child_last_name.' '.(string)$b->child_first_name)
            );
        });

        $body = '';
        foreach ($rows as $index => $row) {
            $body .= '<tr><td>#'.($index + 1).'</td><td>'.esc_html((string)$row->shirt_size ?: '—').'</td><td>'.esc_html(trim((string)$row->child_first_name.' '.(string)$row->child_last_name)).'</td></tr>';
        }
        if ($body === '') $body = '<tr><td colspan="3">Brak w pełni opłaconych uczestników.</td></tr>';

        $date = trim((string)$camp->start_date.' – '.(string)$camp->end_date, ' –');
        $html = '<!doctype html><html lang="pl"><head><meta charset="UTF-8"><style>'
            .'@page{margin:28px}body{font-family:DejaVu Sans,sans-serif;color:#1d2327;font-size:10px}'
            .'h1{font-size:21px;margin:0 0 6px}h2{font-size:14px;margin:0 0 18px;color:#50575e}'
            .'.meta{margin-bottom:16px;padding:10px;background:#f2f4f7;border-radius:5px}.meta strong{font-size:12px}'
            .'table{width:100%;border-collapse:collapse}th,td{border:1px solid #c3c4c7;padding:7px;text-align:left;vertical-align:top}'
            .'th{background:#e9edf2;font-weight:700}.note{margin-top:10px;color:#646970;font-size:8px}'
            .'</style></head><body><h1>Lista strojów</h1><h2>Basketmania Camp System</h2>'
            .'<div class="meta"><strong>'.esc_html((string)$camp->name).'</strong><br>'.esc_html($date ?: 'Brak terminu').' · '.esc_html((string)$camp->location ?: 'Brak miejsca').'</div>'
            .'<table><thead><tr><th>Nr koszulki</th><th>Rozmiar</th><th>Uczestnik</th></tr></thead><tbody>'.$body.'</tbody></table>'
            .'<p class="note">Lista obejmuje wyłącznie w pełni opłaconych uczestników. Kolejność: od najmniejszego do największego rozmiaru stroju; numery koszulek nadawane kolejno od #1.</p>'
            .'</body></html>';

        if (!BCS_PDF::available()) wp_die('Silnik PDF nie jest dostępny.');
        $upload = wp_upload_dir();
        if (!empty($upload['error'])) wp_die(esc_html((string)$upload['error']));
        $path = trailingslashit($upload['basedir']).'bcs-report-'.wp_generate_uuid4().'.pdf';
        if (!BCS_PDF::generate($html, $path, 'Lista strojów')) wp_die('Nie udało się wygenerować pliku PDF.');

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="lista-strojow-turnus-'.$camp_id.'.pdf"');
        header('Content-Length: '.filesize($path));
        readfile($path);
        @unlink($path);
        exit;
    }

    public static function shirt_rank(string $size): int {
        $value = strtoupper(trim($size));
        if ($value === '') return 9999;
        if (preg_match('/\d+/', $value, $match)) return (int)$match[0];
        $order = ['XXS'=>300,'XS'=>310,'S'=>320,'M'=>330,'L'=>340,'XL'=>350,'XXL'=>360,'2XL'=>360,'XXXL'=>370,'3XL'=>370,'4XL'=>380,'5XL'=>390];
        return $order[$value] ?? 9000;
    }

    private static function dashboard_page(): bool {
        return is_admin() && sanitize_key(wp_unslash($_GET['page'] ?? '')) === 'bcs-dashboard';
    }

    public static function admin_head(): void {
        if (!self::dashboard_page()) return;
        ?>
        <style id="bcs-dashboard-layout-064">
            .bcs-new-registrations .bcs-year-chart{
                min-height:130px!important;
                padding:10px 8px 2px!important;
            }
            .bcs-new-registrations .bcs-year-chart__track{
                height:90px!important;
            }
            .bcs-new-registrations .bcs-panel-head{
                margin-bottom:2px!important;
            }
        </style>
        <?php
    }

    public static function admin_footer(): void {
        $page = sanitize_key(wp_unslash($_GET['page'] ?? ''));
        if ($page === 'bcs-dashboard') {
            ?>
            <script id="bcs-dashboard-layout-064-script">
            (() => {
                const arrange = () => {
                    const camps = document.querySelector('.bcs-camp-grid');
                    const chart = document.querySelector('.bcs-new-registrations');
                    if (!camps || !chart || !chart.parentNode) return;
                    if (camps.nextElementSibling !== chart) chart.parentNode.insertBefore(camps, chart);
                    chart.classList.add('bcs-dashboard-chart-064');
                };
                if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', arrange, {once:true});
                else arrange();
                window.setTimeout(arrange, 0);
                window.setTimeout(arrange, 150);
            })();
            </script>
            <?php
        }

        if ($page !== 'bcs-registrations') return;
        ?>
        <script id="bcs-parent-signed-action-064">
        (() => {
            const apply = () => {
                document.querySelectorAll('tr[data-status="agreement_parent_signed"], tr').forEach((row) => {
                    const text = (row.textContent || '').toLocaleLowerCase('pl-PL');
                    if (row.dataset.status !== 'agreement_parent_signed' && !text.includes('oczekuje na podpis organizatora')) return;
                    row.classList.add('bcs-requires-action');
                    row.dataset.requires = '1';
                    let marker = row.querySelector('.bcs-row-action-marker');
                    if (!marker) {
                        marker = document.createElement('span');
                        marker.className = 'bcs-row-action-marker';
                        const first = row.querySelector('td');
                        if (first) first.appendChild(marker);
                    }
                    if (marker) {
                        marker.textContent = 'Wymagające akcji!';
                        marker.title = 'Rodzic podpisał umowę — wymagany podpis Organizatora';
                    }
                });
            };
            if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', apply, {once:true});
            else apply();
            new MutationObserver(apply).observe(document.body, {childList:true, subtree:true});
        })();
        </script>
        <?php
    }
}
