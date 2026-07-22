<?php
if (!defined('ABSPATH')) exit;

class BCS_Feedback {
    private const CAPABILITY = 'manage_options';
    private const STATUSES = ['new', 'in_progress', 'resolved', 'cancelled'];
    private const TYPES = ['bug', 'feature', 'improvement'];

    public static function init(): void {
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('admin_footer', [__CLASS__, 'render_modal']);
        add_action('wp_ajax_bcs_feedback_create', [__CLASS__, 'ajax_create']);
        add_action('admin_init', [__CLASS__, 'handle_status_action']);
    }

    public static function assets(string $hook): void {
        if (strpos($hook, 'bcs-') === false) return;
        wp_localize_script('bcs-admin', 'BCSFeedback', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bcs_feedback_create'),
            'module' => self::current_module(),
            'pageUrl' => self::current_url(),
            'messages' => [
                'required' => 'Wpisz krótki opis zgłoszenia.',
                'saved' => 'Zgłoszenie zostało zapisane.',
                'error' => 'Nie udało się zapisać zgłoszenia. Spróbuj ponownie.',
            ],
        ]);
    }

    public static function new_count(): int {
        global $wpdb;
        $table = BCS_DB::table('feedback');
        return max(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='new'"));
    }

    public static function menu_label(): string {
        $count = self::new_count();
        $badge = $count > 0
            ? ' <span class="awaiting-mod count-' . esc_attr((string) $count) . '"><span class="plugin-count">' . esc_html((string) $count) . '</span></span>'
            : '';
        return 'Feedback' . $badge;
    }

    private static function current_module(): string {
        $page = sanitize_key($_GET['page'] ?? '');
        $labels = [
            'bcs-dashboard' => 'Dashboard Camp',
            'bcs-registrations' => 'CRM – Zgłoszenia',
            'bcs-invoices' => 'Faktury',
            'bcs-camps' => 'Turnusy',
            'bcs-organizers' => 'Organizatorzy',
            'bcs-mailbox' => 'Poczta',
            'bcs-templates' => 'Szablony',
            'bcs-logs' => 'Logi',
            'bcs-settings' => 'Ustawienia',
            'bcs-feedback' => 'Feedback',
            'bcs-service-center' => 'Centrum serwisowe',
        ];
        return $labels[$page] ?? ($page !== '' ? $page : 'Panel administratora');
    }

    private static function current_url(): string {
        $scheme = is_ssl() ? 'https://' : 'http://';
        $host = sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'] ?? ''));
        $uri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));
        return esc_url_raw($scheme . $host . $uri);
    }

    public static function render_modal(): void {
        if (!current_user_can(self::CAPABILITY)) return;
        $page = sanitize_key($_GET['page'] ?? '');
        if (!str_starts_with($page, 'bcs-')) return;
        ?>
        <div class="bcs-feedback-tools" data-bcs-feedback-tools>
            <button type="button" class="button button-primary bcs-feedback-open" data-bcs-feedback-open>
                <span class="dashicons dashicons-megaphone" aria-hidden="true"></span>
                Zgłoś uwagę
            </button>
        </div>
        <div class="bcs-feedback-modal" data-bcs-feedback-modal hidden>
            <div class="bcs-feedback-backdrop" data-bcs-feedback-close></div>
            <section class="bcs-feedback-dialog" role="dialog" aria-modal="true" aria-labelledby="bcs-feedback-title">
                <button type="button" class="bcs-feedback-close" data-bcs-feedback-close aria-label="Zamknij">&times;</button>
                <div class="bcs-feedback-dialog-head">
                    <span class="dashicons dashicons-megaphone" aria-hidden="true"></span>
                    <div><h2 id="bcs-feedback-title">Nowe zgłoszenie</h2><p>Zgłaszasz uwagę w module: <strong><?php echo esc_html(self::current_module()); ?></strong></p></div>
                </div>
                <form data-bcs-feedback-form>
                    <label for="bcs-feedback-type">Rodzaj zgłoszenia</label>
                    <select id="bcs-feedback-type" name="type"><option value="bug">Błąd</option><option value="feature">Nowa funkcjonalność</option><option value="improvement">Poprawka / usprawnienie</option></select>
                    <label for="bcs-feedback-description">Krótki opis</label>
                    <textarea id="bcs-feedback-description" name="description" rows="5" maxlength="2000" required placeholder="Opisz krótko, co nie działa albo co należy dodać..."></textarea>
                    <div class="bcs-feedback-message" data-bcs-feedback-message aria-live="polite"></div>
                    <div class="bcs-feedback-actions"><button type="button" class="button" data-bcs-feedback-close>Anuluj</button><button type="submit" class="button button-primary">Wyślij zgłoszenie</button></div>
                </form>
            </section>
        </div>
        <?php
    }

    public static function ajax_create(): void {
        if (!current_user_can(self::CAPABILITY)) wp_send_json_error(['message' => 'Brak uprawnień.'], 403);
        check_ajax_referer('bcs_feedback_create', 'nonce');
        $type = sanitize_key($_POST['type'] ?? 'bug');
        if (!in_array($type, self::TYPES, true)) $type = 'bug';
        $description = sanitize_textarea_field(wp_unslash($_POST['description'] ?? ''));
        if ($description === '') wp_send_json_error(['message' => 'Wpisz krótki opis zgłoszenia.'], 422);
        $module = sanitize_text_field(wp_unslash($_POST['module'] ?? self::current_module()));
        $page_url = esc_url_raw(wp_unslash($_POST['page_url'] ?? self::current_url()));
        $user = wp_get_current_user();
        global $wpdb;
        $inserted = $wpdb->insert(BCS_DB::table('feedback'), [
            'created_by' => get_current_user_id(), 'created_by_name' => $user->display_name ?: $user->user_login,
            'module' => $module, 'page_url' => $page_url, 'type' => $type, 'status' => 'new',
            'description' => $description, 'created_at' => BCS_Utils::now(), 'updated_at' => BCS_Utils::now(),
        ], ['%d','%s','%s','%s','%s','%s','%s','%s','%s']);
        if (!$inserted) wp_send_json_error(['message' => 'Nie udało się zapisać zgłoszenia.'], 500);
        wp_send_json_success(['message' => 'Zgłoszenie #' . (int) $wpdb->insert_id . ' zostało zapisane.']);
    }

    public static function handle_status_action(): void {
        if (!current_user_can(self::CAPABILITY) || !isset($_POST['bcs_feedback_status'])) return;
        $id = absint($_POST['feedback_id'] ?? 0);
        check_admin_referer('bcs_feedback_status_' . $id);
        $status = sanitize_key($_POST['status'] ?? '');
        if (!$id || !in_array($status, self::STATUSES, true)) return;
        global $wpdb;
        $data = ['status'=>$status,'updated_at'=>BCS_Utils::now(),'resolved_by'=>null,'resolved_at'=>null];
        $formats = ['%s','%s','%d','%s'];
        if ($status === 'resolved') { $data['resolved_by'] = get_current_user_id(); $data['resolved_at'] = BCS_Utils::now(); }
        $wpdb->update(BCS_DB::table('feedback'), $data, ['id' => $id], $formats, ['%d']);
        wp_safe_redirect(add_query_arg(['page'=>'bcs-feedback','status_filter'=>sanitize_key($_GET['status_filter'] ?? ''),'updated'=>1], admin_url('admin.php'))); exit;
    }

    public static function page(): void {
        if (!current_user_can(self::CAPABILITY)) wp_die('Brak uprawnień.');
        global $wpdb;
        $filter = sanitize_key($_GET['status_filter'] ?? '');
        $where = in_array($filter, self::STATUSES, true) ? $wpdb->prepare(' WHERE status=%s', $filter) : '';
        $rows = $wpdb->get_results("SELECT * FROM " . BCS_DB::table('feedback') . $where . " ORDER BY created_at DESC, id DESC LIMIT 500");
        $counts = [];
        foreach (self::STATUSES as $status) $counts[$status] = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . BCS_DB::table('feedback') . " WHERE status=%s", $status));
        $type_labels = ['bug'=>'Błąd','feature'=>'Nowa funkcjonalność','improvement'=>'Poprawka / usprawnienie'];
        $status_labels = ['new'=>'Nowe','in_progress'=>'W trakcie','resolved'=>'Rozwiązano','cancelled'=>'Anulowano'];
        echo '<div class="wrap bcs-admin bcs-feedback-page"><div class="bcs-page-head"><div><h1>Feedback</h1><p>Zgłoszenia błędów, poprawek i nowych funkcjonalności od administratorów systemu.</p></div><span class="bcs-count">'.count($rows).' zgłoszeń</span></div>';
        if (isset($_GET['updated'])) echo '<div class="notice notice-success is-dismissible"><p>Status zgłoszenia został zaktualizowany.</p></div>';
        echo '<nav class="bcs-feedback-filters">';
        foreach ([''=>'Wszystkie'] + $status_labels as $key => $label) {
            $active = $filter === $key ? ' is-active' : '';
            $count = $key === '' ? array_sum($counts) : ($counts[$key] ?? 0);
            echo '<a class="'.$active.'" href="'.esc_url(add_query_arg(['page'=>'bcs-feedback','status_filter'=>$key], admin_url('admin.php'))).'">'.esc_html($label).' <span>'.(int)$count.'</span></a>';
        }
        echo '</nav><div class="bcs-panel bcs-feedback-list">';
        if (!$rows) {
            echo '<div class="bcs-empty-state"><span class="dashicons dashicons-megaphone"></span><h2>Brak zgłoszeń</h2><p>Nowe uwagi administratorów pojawią się tutaj.</p></div>';
        } else {
            echo '<div class="bcs-table-wrap"><table class="widefat striped bcs-table bcs-feedback-table"><thead><tr><th>ID</th><th>Data i autor</th><th>Moduł</th><th>Rodzaj</th><th>Opis</th><th>Status</th><th>Akcje</th></tr></thead><tbody>';
            foreach ($rows as $row) {
                $description = (string)$row->description;
                echo '<tr><td><strong>#'.(int)$row->id.'</strong></td>';
                echo '<td>'.esc_html(mysql2date('d.m.Y H:i', $row->created_at)).'<small>'.esc_html($row->created_by_name ?: 'Administrator').'</small></td>';
                echo '<td><strong>'.esc_html($row->module).'</strong>'.($row->page_url ? '<a class="bcs-feedback-url" target="_blank" rel="noopener" href="'.esc_url($row->page_url).'">Otwórz stronę</a>' : '').'</td>';
                echo '<td><span class="bcs-feedback-type type-'.esc_attr($row->type).'">'.esc_html($type_labels[$row->type] ?? $row->type).'</span></td>';
                echo '<td class="bcs-feedback-description"><div>'.nl2br(esc_html($description)).'</div><button type="button" class="button button-small bcs-copy-feedback" data-copy="'.esc_attr($description).'" title="Kopiuj pełny opis"><span class="dashicons dashicons-clipboard" aria-hidden="true"></span><span class="screen-reader-text">Kopiuj opis</span></button></td>';
                echo '<td><span class="bcs-feedback-status status-'.esc_attr($row->status).'">'.esc_html($status_labels[$row->status] ?? $row->status).'</span></td><td><div class="bcs-feedback-row-actions">';
                foreach (['in_progress'=>'W trakcie','resolved'=>'Rozwiązano','cancelled'=>'Anulowano'] as $status => $label) {
                    if ($row->status === $status) continue;
                    echo '<form method="post">'; wp_nonce_field('bcs_feedback_status_' . (int)$row->id);
                    echo '<input type="hidden" name="feedback_id" value="'.(int)$row->id.'"><input type="hidden" name="status" value="'.esc_attr($status).'"><button class="button bcs-feedback-action status-'.esc_attr($status).'" name="bcs_feedback_status" value="1">'.esc_html($label).'</button></form>';
                }
                echo '</div></td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div></div>';
        ?>
        <script>
        document.addEventListener('click', async function (event) {
            const button = event.target.closest('.bcs-copy-feedback');
            if (!button) return;
            const text = button.getAttribute('data-copy') || '';
            try {
                await navigator.clipboard.writeText(text);
            } catch (error) {
                const area = document.createElement('textarea'); area.value = text; area.style.position = 'fixed'; area.style.opacity = '0';
                document.body.appendChild(area); area.select(); document.execCommand('copy'); area.remove();
            }
            const icon = button.querySelector('.dashicons');
            const oldClass = icon ? icon.className : '';
            if (icon) icon.className = 'dashicons dashicons-yes';
            button.setAttribute('title', 'Skopiowano opis');
            setTimeout(function () { if (icon) icon.className = oldClass; button.setAttribute('title', 'Kopiuj pełny opis'); }, 1800);
        });
        </script>
        <?php
    }
}
