<?php
if (!defined('ABSPATH')) exit;

final class BCS_Camp_Reports {
    public static function init(): void {
        add_action('admin_post_bcs_camp_shirts_pdf', [self::class, 'shirts_pdf']);
        add_action('admin_post_bcs_camp_participants_pdf', [self::class, 'participants_pdf']);
        add_action('admin_footer', [self::class, 'admin_enhancements']);
    }

    public static function admin_enhancements(): void {
        if (!current_user_can('manage_options')) return;
        $page = sanitize_key(wp_unslash($_GET['page'] ?? ''));
        if (!in_array($page, ['bcs-dashboard', 'bcs-camps'], true)) return;

        global $wpdb;
        $camps = $wpdb->get_results("SELECT id FROM " . BCS_DB::table('camps') . " ORDER BY start_date DESC");
        $links = [];
        foreach ($camps as $camp) {
            $id = (int)$camp->id;
            $links[(string)$id] = [
                'shirts' => wp_nonce_url(admin_url('admin-post.php?action=bcs_camp_shirts_pdf&camp_id=' . $id), 'bcs_camp_shirts_pdf_' . $id),
                'participants' => wp_nonce_url(admin_url('admin-post.php?action=bcs_camp_participants_pdf&camp_id=' . $id), 'bcs_camp_participants_pdf_' . $id),
            ];
        }

        $chart = null;
        if ($page === 'bcs-dashboard') {
            $year = (int)current_time('Y');
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT MONTH(created_at) month_number, COUNT(*) total FROM " . BCS_DB::table('registrations') . " WHERE YEAR(created_at)=%d GROUP BY MONTH(created_at)",
                $year
            ));
            $values = array_fill(1, 12, 0);
            foreach ($rows as $row) $values[(int)$row->month_number] = (int)$row->total;
            $chart = [
                'year' => $year,
                'values' => array_values($values),
                'labels' => ['Sty','Lut','Mar','Kwi','Maj','Cze','Lip','Sie','Wrz','Paź','Lis','Gru'],
                'total' => array_sum($values),
            ];
        }
        ?>
        <style>
            .bcs-report-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
            .bcs-year-chart{display:grid;grid-template-columns:repeat(12,minmax(38px,1fr));gap:10px;align-items:end;min-height:260px;padding:22px 8px 4px}
            .bcs-year-chart__month{display:flex;flex-direction:column;align-items:center;gap:7px;min-width:0}
            .bcs-year-chart__value{font-weight:700;font-size:13px}
            .bcs-year-chart__track{height:180px;width:100%;max-width:54px;background:#eef1f5;border-radius:8px;display:flex;align-items:flex-end;overflow:hidden}
            .bcs-year-chart__bar{width:100%;min-height:2px;background:#2271b1;border-radius:8px 8px 0 0}
            .bcs-year-chart__label{font-size:12px;color:#50575e;font-weight:600}
            .bcs-chart-summary{display:flex;gap:18px;align-items:center;flex-wrap:wrap}
            .bcs-chart-summary strong{font-size:22px}
            @media(max-width:900px){.bcs-year-chart{overflow-x:auto;grid-template-columns:repeat(12,58px)}}
        </style>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const links = <?php echo wp_json_encode($links); ?>;
            document.querySelectorAll('a[href*="page=bcs-camps&edit="]').forEach(function(editLink){
                const match = editLink.href.match(/[?&]edit=(\d+)/);
                if (!match || !links[match[1]]) return;
                const container = editLink.closest('.bcs-card-actions') || editLink.parentElement;
                if (!container || container.querySelector('[data-bcs-reports="'+match[1]+'"]')) return;
                container.classList.add('bcs-report-actions');
                const shirts = document.createElement('a');
                shirts.className = 'button';
                shirts.dataset.bcsReports = match[1];
                shirts.href = links[match[1]].shirts;
                shirts.textContent = 'Lista strojów';
                shirts.target = '_blank';
                const participants = document.createElement('a');
                participants.className = 'button';
                participants.href = links[match[1]].participants;
                participants.textContent = 'Lista uczestników';
                participants.target = '_blank';
                editLink.insertAdjacentElement('afterend', participants);
                editLink.insertAdjacentElement('afterend', shirts);
            });

            <?php if ($chart): ?>
            const panel = document.querySelector('.bcs-new-registrations');
            if (panel) {
                const chart = <?php echo wp_json_encode($chart); ?>;
                const max = Math.max(1, ...chart.values);
                const bars = chart.values.map(function(value, index){
                    const height = Math.max(value > 0 ? 5 : 1, Math.round((value / max) * 100));
                    return '<div class="bcs-year-chart__month"><span class="bcs-year-chart__value">'+value+'</span><div class="bcs-year-chart__track" title="'+chart.labels[index]+': '+value+'"><span class="bcs-year-chart__bar" style="height:'+height+'%"></span></div><span class="bcs-year-chart__label">'+chart.labels[index]+'</span></div>';
                }).join('');
                panel.innerHTML = '<div class="bcs-panel-head"><div><h2>Zgłoszenia w '+chart.year+' roku</h2><p>Liczba zgłoszeń w poszczególnych miesiącach całego roku.</p></div><div class="bcs-chart-summary"><span>Łącznie</span><strong>'+chart.total+'</strong></div></div><div class="bcs-year-chart">'+bars+'</div>';
            }
            <?php endif; ?>
        });
        </script>
        <?php
    }

    public static function shirts_pdf(): void {
        $camp_id = absint($_GET['camp_id'] ?? 0);
        self::guard('bcs_camp_shirts_pdf_' . $camp_id);
        global $wpdb;
        $camp = self::camp($camp_id);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT child_first_name, child_last_name, shirt_size FROM " . BCS_DB::table('registrations') . " WHERE camp_id=%d AND status<>'cancelled' ORDER BY child_last_name, child_first_name",
            $camp_id
        ));
        usort($rows, static function($a, $b): int {
            $rank_a = self::shirt_rank((string)$a->shirt_size);
            $rank_b = self::shirt_rank((string)$b->shirt_size);
            if ($rank_a === $rank_b) return strcasecmp((string)$a->child_last_name . (string)$a->child_first_name, (string)$b->child_last_name . (string)$b->child_first_name);
            return $rank_a <=> $rank_b;
        });

        $body = '';
        foreach ($rows as $index => $row) {
            $body .= '<tr><td>#' . ($index + 1) . '</td><td>' . esc_html((string)$row->shirt_size ?: '—') . '</td><td>' . esc_html(trim((string)$row->child_first_name . ' ' . (string)$row->child_last_name)) . '</td></tr>';
        }
        if ($body === '') $body = '<tr><td colspan="3">Brak uczestników.</td></tr>';
        $html = self::document_html('Lista strojów', $camp, '<table><thead><tr><th>Nr koszulki</th><th>Rozmiar</th><th>Uczestnik</th></tr></thead><tbody>' . $body . '</tbody></table>');
        self::stream_pdf($html, 'lista-strojow-turnus-' . $camp_id . '.pdf', 'Lista strojów');
    }

    public static function participants_pdf(): void {
        $camp_id = absint($_GET['camp_id'] ?? 0);
        self::guard('bcs_camp_participants_pdf_' . $camp_id);
        global $wpdb;
        $camp = self::camp($camp_id);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT child_first_name, child_last_name, child_birth_date, dietary_notes, medical_notes, camp_notes FROM " . BCS_DB::table('registrations') . " WHERE camp_id=%d AND status<>'cancelled' ORDER BY CASE WHEN child_birth_date IS NULL OR child_birth_date='0000-00-00' THEN 1 ELSE 0 END, child_birth_date ASC, child_last_name ASC, child_first_name ASC",
            $camp_id
        ));
        $reference_date = !empty($camp->start_date) ? (string)$camp->start_date : current_time('Y-m-d');
        $body = '';
        foreach ($rows as $index => $row) {
            $body .= '<tr><td>' . ($index + 1) . '</td><td>' . esc_html((string)$row->child_first_name) . '</td><td>' . esc_html((string)$row->child_last_name) . '</td><td>' . esc_html(self::clean_note((string)$row->dietary_notes)) . '</td><td>' . esc_html(self::clean_note((string)$row->medical_notes)) . '</td><td>' . esc_html(self::clean_note((string)$row->camp_notes)) . '</td><td>' . esc_html(self::age((string)$row->child_birth_date, $reference_date)) . '</td></tr>';
        }
        if ($body === '') $body = '<tr><td colspan="7">Brak uczestników.</td></tr>';
        $table = '<table class="compact"><thead><tr><th>Lp.</th><th>Imię</th><th>Nazwisko</th><th>Alergie</th><th>Potrzeby specjalne</th><th>Inne informacje od rodzica</th><th>Wiek</th></tr></thead><tbody>' . $body . '</tbody></table>';
        $html = self::document_html('Aktualna lista uczestników', $camp, $table, 'Wiek obliczony na dzień rozpoczęcia turnusu. Lista posortowana od najstarszego uczestnika.');
        self::stream_pdf($html, 'lista-uczestnikow-turnus-' . $camp_id . '.pdf', 'Lista uczestników');
    }

    private static function shirt_rank(string $size): int {
        $value = strtoupper(trim($size));
        if ($value === '') return 9999;
        if (preg_match('/\d+/', $value, $match)) return (int)$match[0];
        $order = ['XXS'=>300, 'XS'=>310, 'S'=>320, 'M'=>330, 'L'=>340, 'XL'=>350, 'XXL'=>360, '2XL'=>360, 'XXXL'=>370, '3XL'=>370, '4XL'=>380, '5XL'=>390];
        return $order[$value] ?? 9000;
    }

    private static function age(string $birth_date, string $reference_date): string {
        if ($birth_date === '' || $birth_date === '0000-00-00') return '—';
        try {
            $birth = new DateTimeImmutable($birth_date);
            $reference = new DateTimeImmutable($reference_date);
            if ($birth > $reference) return '—';
            return (string)$birth->diff($reference)->y;
        } catch (Throwable $e) {
            return '—';
        }
    }

    private static function clean_note(string $value): string {
        $value = trim(wp_strip_all_tags($value));
        return $value !== '' ? $value : '—';
    }

    private static function camp(int $camp_id): object {
        global $wpdb;
        $camp = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . BCS_DB::table('camps') . " WHERE id=%d", $camp_id));
        if (!$camp) wp_die('Nie znaleziono turnusu.');
        return $camp;
    }

    private static function document_html(string $title, object $camp, string $content, string $note = ''): string {
        $date = trim((string)$camp->start_date . ' – ' . (string)$camp->end_date, ' –');
        return '<!doctype html><html lang="pl"><head><meta charset="UTF-8"><style>@page{margin:28px}body{font-family:DejaVu Sans,sans-serif;color:#1d2327;font-size:10px}h1{font-size:21px;margin:0 0 6px}h2{font-size:14px;margin:0 0 18px;color:#50575e}.meta{margin-bottom:16px;padding:10px;background:#f2f4f7;border-radius:5px}.meta strong{font-size:12px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #c3c4c7;padding:7px;text-align:left;vertical-align:top}th{background:#e9edf2;font-weight:700}.compact{font-size:8px}.compact th,.compact td{padding:5px}.note{margin-top:10px;color:#646970;font-size:8px}</style></head><body><h1>' . esc_html($title) . '</h1><h2>Basketmania Camp System</h2><div class="meta"><strong>' . esc_html((string)$camp->name) . '</strong><br>' . esc_html($date ?: 'Brak terminu') . ' · ' . esc_html((string)$camp->location ?: 'Brak miejsca') . '</div>' . $content . ($note !== '' ? '<p class="note">' . esc_html($note) . '</p>' : '') . '</body></html>';
    }

    private static function stream_pdf(string $html, string $filename, string $title): void {
        if (!BCS_PDF::available()) wp_die('Silnik PDF nie jest dostępny.');
        $upload = wp_upload_dir();
        if (!empty($upload['error'])) wp_die(esc_html((string)$upload['error']));
        $path = trailingslashit($upload['basedir']) . 'bcs-report-' . wp_generate_uuid4() . '.pdf';
        if (!BCS_PDF::generate($html, $path, $title)) wp_die('Nie udało się wygenerować pliku PDF.');
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
}
