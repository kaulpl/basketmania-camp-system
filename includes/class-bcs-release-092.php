<?php
if (!defined('ABSPATH')) exit;

/**
 * 0.92 – listy uczestników/strojów, numery koszulek per turnus i sugestia rozmiaru ze wzrostu.
 */
final class BCS_Release_092 {
    private const DB_OPTION = 'bcs_release_092_db_version';
    private const DB_VERSION = '0.92';
    private const SCRIPT_HANDLE = 'bcs-shirt-size-suggestion-092';

    public static function init(): void {
        self::maybe_upgrade();

        // BCS_Camp_Reports::init() musi zostać uruchomione przed tym init(), aby można było
        // bezpiecznie przejąć te same adresy admin-post bez zmiany linków w panelu turnusów.
        remove_action('admin_post_bcs_camp_shirts_pdf', ['BCS_Camp_Reports', 'shirts_pdf']);
        remove_action('admin_post_bcs_camp_participants_pdf', ['BCS_Camp_Reports', 'participants_pdf']);
        add_action('admin_post_bcs_camp_shirts_pdf', [__CLASS__, 'shirts_pdf']);
        add_action('admin_post_bcs_camp_participants_pdf', [__CLASS__, 'participants_pdf']);

        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_front_assets'], 110);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets'], 110);
    }

    private static function maybe_upgrade(): void {
        if ((string)get_option(self::DB_OPTION, '') === self::DB_VERSION) return;
        global $wpdb;
        $table = BCS_DB::table('registrations');
        $column = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'jersey_number'));
        if ($column === null) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN jersey_number SMALLINT UNSIGNED NULL AFTER shirt_size");
        }
        $index = $wpdb->get_var("SHOW INDEX FROM {$table} WHERE Key_name='camp_jersey_number'");
        if ($index === null) {
            $wpdb->query("ALTER TABLE {$table} ADD KEY camp_jersey_number (camp_id, jersey_number)");
        }
        update_option(self::DB_OPTION, self::DB_VERSION, false);
    }

    public static function shirt_sizes(): array {
        if (class_exists('BCS_Release_065')) return BCS_Release_065::shirt_sizes();
        return [
            '128-134','134-140','140-146','146-152','152-158','158-164',
            'S-164-170','M-170-176','L-176-182','XL-182-188','2XL-188-194','3XL-194-200',
        ];
    }

    /**
     * Dolna granica należy do rozmiaru, górna przechodzi do następnego rozmiaru.
     * Dla wartości poza tabelą zwracamy odpowiednio najmniejszy/największy dostępny rozmiar.
     */
    public static function suggest_shirt_size(int $height): string {
        $sizes = self::shirt_sizes();
        if (!$sizes || $height <= 0) return '';

        $first = $sizes[0];
        $last = $sizes[count($sizes) - 1];
        foreach ($sizes as $size) {
            preg_match_all('/\d+/', (string)$size, $matches);
            $numbers = array_map('intval', (array)($matches[0] ?? []));
            if (count($numbers) < 2) continue;
            $min = $numbers[count($numbers) - 2];
            $max = $numbers[count($numbers) - 1];
            if ($height < $min) return $first;
            if ($height >= $min && $height < $max) return (string)$size;
        }
        return (string)$last;
    }

    /** @return array{group:int,label:int,min:int,max:int,text:string} */
    public static function shirt_sort_key(string $size): array {
        $value = strtoupper(trim($size));
        if ($value === '') return ['group'=>9,'label'=>999,'min'=>9999,'max'=>9999,'text'=>''];

        preg_match_all('/\d+/', $value, $matches);
        $numbers = array_map('intval', (array)($matches[0] ?? []));
        $min = $numbers ? $numbers[max(0, count($numbers) - 2)] : 9999;
        $max = $numbers ? $numbers[count($numbers) - 1] : $min;

        // Rozmiar wyłącznie liczbowy / liczbowy przedział zawsze jest mniejszy
        // od rozmiaru oznaczonego literą, nawet gdy ich zakresy wysokości się stykają.
        if (preg_match('/^\d+(?:\s*-\s*\d+)?$/', $value)) {
            return ['group'=>0,'label'=>0,'min'=>$min,'max'=>$max,'text'=>$value];
        }

        $label = 900;
        $labels = [
            'XXS'=>10,'XS'=>20,'S'=>30,'M'=>40,'L'=>50,'XL'=>60,
            'XXL'=>70,'2XL'=>70,'XXXL'=>80,'3XL'=>80,'4XL'=>90,'5XL'=>100,
        ];
        foreach ($labels as $prefix => $rank) {
            if (preg_match('/^'.preg_quote($prefix, '/').'(?:\b|-|\s|\d)/', $value)) {
                $label = $rank;
                break;
            }
        }
        return ['group'=>1,'label'=>$label,'min'=>$min,'max'=>$max,'text'=>$value];
    }

    public static function compare_shirt_sizes(string $a, string $b): int {
        $ka = self::shirt_sort_key($a);
        $kb = self::shirt_sort_key($b);
        foreach (['group','label','min','max'] as $key) {
            if ($ka[$key] !== $kb[$key]) return $ka[$key] <=> $kb[$key];
        }
        return strnatcasecmp($ka['text'], $kb['text']);
    }

    /**
     * Numer koszulki jest własnością konkretnego zgłoszenia, a zgłoszenie należy do jednego turnusu.
     * Za każdym wygenerowaniem dowolnej listy dla turnusu numerujemy aktywnych uczestników 1..N
     * w kanonicznej kolejności od najmłodszego do najstarszego. Dzięki temu sortowanie listy strojów
     * nie zmienia numeru konkretnego uczestnika.
     *
     * @return array{success:bool,count:int,message:string}
     */
    public static function refresh_jersey_numbers(int $campId): array {
        if ($campId <= 0) return ['success'=>false,'count'=>0,'message'=>'Nieprawidłowy turnus.'];
        global $wpdb;
        $table = BCS_DB::table('registrations');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM {$table} WHERE camp_id=%d AND status<>'cancelled' "
            ."ORDER BY CASE WHEN child_birth_date IS NULL OR child_birth_date='0000-00-00' THEN 1 ELSE 0 END, "
            ."child_birth_date DESC, child_last_name ASC, child_first_name ASC, id ASC",
            $campId
        ));

        $wpdb->query('START TRANSACTION');
        try {
            $reset = $wpdb->query($wpdb->prepare("UPDATE {$table} SET jersey_number=NULL WHERE camp_id=%d", $campId));
            if ($reset === false) throw new RuntimeException('Nie udało się wyzerować numerów koszulek.');

            foreach ((array)$rows as $index => $row) {
                $updated = $wpdb->update(
                    $table,
                    ['jersey_number'=>$index + 1],
                    ['id'=>(int)$row->id, 'camp_id'=>$campId],
                    ['%d'],
                    ['%d','%d']
                );
                if ($updated === false) throw new RuntimeException('Nie udało się zapisać numeru koszulki.');
            }
            $wpdb->query('COMMIT');
            return ['success'=>true,'count'=>count((array)$rows),'message'=>'Numery koszulek zostały zaktualizowane dla turnusu.'];
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            return ['success'=>false,'count'=>0,'message'=>$e->getMessage()];
        }
    }

    private static function enqueue_script(): void {
        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            BCS_URL.'assets/js/shirt-size-suggestion-092.js',
            ['bcs-shirt-size-select-065'],
            BCS_VERSION,
            true
        );
        wp_localize_script(self::SCRIPT_HANDLE, 'BCSShirtSuggestion092', [
            'sizes'=>self::shirt_sizes(),
            'hintPrefix'=>'Sugerowany rozmiar dla podanego wzrostu:',
        ]);
    }

    public static function enqueue_front_assets(): void {
        self::enqueue_script();
    }

    public static function enqueue_admin_assets(string $hook): void {
        if (strpos($hook, 'bcs-') === false) return;
        self::enqueue_script();
    }

    public static function shirts_pdf(): void {
        $campId = absint($_GET['camp_id'] ?? 0);
        self::guard('bcs_camp_shirts_pdf_'.$campId);
        $refresh = self::refresh_jersey_numbers($campId);
        if (empty($refresh['success'])) wp_die(esc_html((string)$refresh['message']));

        global $wpdb;
        $camp = self::camp($campId);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT jersey_number, child_first_name, child_last_name, shirt_size FROM ".BCS_DB::table('registrations')." WHERE camp_id=%d AND status<>'cancelled'",
            $campId
        ));
        usort($rows, static function($a, $b): int {
            $size = BCS_Release_092::compare_shirt_sizes((string)$a->shirt_size, (string)$b->shirt_size);
            if ($size !== 0) return $size;
            $name = strcasecmp((string)$a->child_last_name.(string)$a->child_first_name, (string)$b->child_last_name.(string)$b->child_first_name);
            if ($name !== 0) return $name;
            return (int)$a->jersey_number <=> (int)$b->jersey_number;
        });

        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr><td>#'.(int)$row->jersey_number.'</td><td>'.esc_html((string)$row->shirt_size ?: '—').'</td><td>'.esc_html(trim((string)$row->child_first_name.' '.(string)$row->child_last_name)).'</td></tr>';
        }
        if ($body === '') $body = '<tr><td colspan="3">Brak uczestników.</td></tr>';
        $html = self::document_html(
            'Lista strojów',
            $camp,
            '<table><thead><tr><th>Nr koszulki</th><th>Rozmiar</th><th>Uczestnik</th></tr></thead><tbody>'.$body.'</tbody></table>',
            'Rozmiary posortowane od najmniejszego do największego. Rozmiary literowe są większe od rozmiarów bez oznaczeń literowych.'
        );
        self::stream_pdf($html, 'lista-strojow-turnus-'.$campId.'.pdf', 'Lista strojów');
    }

    public static function participants_pdf(): void {
        $campId = absint($_GET['camp_id'] ?? 0);
        self::guard('bcs_camp_participants_pdf_'.$campId);
        $refresh = self::refresh_jersey_numbers($campId);
        if (empty($refresh['success'])) wp_die(esc_html((string)$refresh['message']));

        global $wpdb;
        $camp = self::camp($campId);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT jersey_number, child_first_name, child_last_name, child_birth_date, dietary_notes, medical_notes, camp_notes FROM ".BCS_DB::table('registrations')." WHERE camp_id=%d AND status<>'cancelled' "
            ."ORDER BY CASE WHEN child_birth_date IS NULL OR child_birth_date='0000-00-00' THEN 1 ELSE 0 END, child_birth_date DESC, child_last_name ASC, child_first_name ASC, id ASC",
            $campId
        ));
        $referenceDate = !empty($camp->start_date) ? (string)$camp->start_date : current_time('Y-m-d');
        $body = '';
        foreach ($rows as $index => $row) {
            $body .= '<tr><td>'.($index + 1).'</td><td>#'.(int)$row->jersey_number.'</td><td>'.esc_html((string)$row->child_first_name).'</td><td>'.esc_html((string)$row->child_last_name).'</td><td>'.esc_html(self::clean_note((string)$row->dietary_notes)).'</td><td>'.esc_html(self::clean_note((string)$row->medical_notes)).'</td><td>'.esc_html(self::clean_note((string)$row->camp_notes)).'</td><td>'.esc_html(self::age((string)$row->child_birth_date, $referenceDate)).'</td></tr>';
        }
        if ($body === '') $body = '<tr><td colspan="8">Brak uczestników.</td></tr>';
        $table = '<table class="compact"><thead><tr><th>Lp.</th><th>Nr koszulki</th><th>Imię</th><th>Nazwisko</th><th>Alergie</th><th>Potrzeby specjalne</th><th>Inne informacje od rodzica</th><th>Wiek</th></tr></thead><tbody>'.$body.'</tbody></table>';
        $html = self::document_html(
            'Aktualna lista uczestników',
            $camp,
            $table,
            'Wiek obliczony na dzień rozpoczęcia turnusu. Lista posortowana od najmłodszego do najstarszego uczestnika.'
        );
        self::stream_pdf($html, 'lista-uczestnikow-turnus-'.$campId.'.pdf', 'Lista uczestników');
    }

    private static function age(string $birthDate, string $referenceDate): string {
        if ($birthDate === '' || $birthDate === '0000-00-00') return '—';
        try {
            $birth = new DateTimeImmutable($birthDate);
            $reference = new DateTimeImmutable($referenceDate);
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

    private static function camp(int $campId): object {
        global $wpdb;
        $camp = $wpdb->get_row($wpdb->prepare("SELECT * FROM ".BCS_DB::table('camps')." WHERE id=%d", $campId));
        if (!$camp) wp_die('Nie znaleziono turnusu.');
        return $camp;
    }

    private static function document_html(string $title, object $camp, string $content, string $note = ''): string {
        $date = trim((string)$camp->start_date.' – '.(string)$camp->end_date, ' –');
        return '<!doctype html><html lang="pl"><head><meta charset="UTF-8"><style>@page{margin:28px}body{font-family:DejaVu Sans,sans-serif;color:#1d2327;font-size:10px}h1{font-size:21px;margin:0 0 6px}h2{font-size:14px;margin:0 0 18px;color:#50575e}.meta{margin-bottom:16px;padding:10px;background:#f2f4f7;border-radius:5px}.meta strong{font-size:12px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #c3c4c7;padding:7px;text-align:left;vertical-align:top}th{background:#e9edf2;font-weight:700}.compact{font-size:8px}.compact th,.compact td{padding:5px}.note{margin-top:10px;color:#646970;font-size:8px}</style></head><body><h1>'.esc_html($title).'</h1><h2>Basketmania Camp System</h2><div class="meta"><strong>'.esc_html((string)$camp->name).'</strong><br>'.esc_html($date ?: 'Brak terminu').' · '.esc_html((string)$camp->location ?: 'Brak miejsca').'</div>'.$content.($note !== '' ? '<p class="note">'.esc_html($note).'</p>' : '').'</body></html>';
    }

    private static function stream_pdf(string $html, string $filename, string $title): void {
        if (!BCS_PDF::available()) wp_die('Silnik PDF nie jest dostępny.');
        $upload = wp_upload_dir();
        if (!empty($upload['error'])) wp_die(esc_html((string)$upload['error']));
        $path = trailingslashit($upload['basedir']).'bcs-report-'.wp_generate_uuid4().'.pdf';
        if (!BCS_PDF::generate($html, $path, $title)) wp_die('Nie udało się wygenerować pliku PDF.');
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="'.sanitize_file_name($filename).'"');
        header('Content-Length: '.filesize($path));
        readfile($path);
        @unlink($path);
        exit;
    }

    private static function guard(string $action): void {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        check_admin_referer($action);
    }
}
