<?php
if (!defined('ABSPATH')) exit;

/**
 * 1.09 – pobranie aktualnej paczki archiwalnej bez zamykania turnusu.
 *
 * Wykorzystuje ten sam generator paczki co 1.08, ale po jej utworzeniu przywraca
 * stan turnusu i dotychczasowe metadane archiwizacji. Tymczasowy ZIP jest
 * przekazywany administratorowi i usuwany po zakończeniu żądania.
 */
final class BCS_Release_109 {
    private const DOWNLOAD_LIVE_ACTION = 'bcs_download_live_camp_archive_109';

    public static function init(): void {
        add_action('admin_post_'.self::DOWNLOAD_LIVE_ACTION, [__CLASS__, 'handle_download_live']);
        add_action('admin_footer', [__CLASS__, 'render_download_live_button']);
    }

    public static function render_download_live_button(): void {
        if (!is_admin() || !current_user_can('manage_options')) return;
        if (sanitize_key((string)($_GET['page'] ?? '')) !== 'bcs-camp-archive') return;
        $campId = absint($_GET['camp_id'] ?? 0);
        if (!$campId) return;

        global $wpdb;
        $status = (string)$wpdb->get_var($wpdb->prepare(
            'SELECT status FROM '.BCS_DB::table('camps').' WHERE id=%d',
            $campId
        ));
        if ($status === '' || $status === 'archived') return;

        $html = '<div class="bcs-subpanel bcs-download-live-archive-109" style="margin:16px 0">'
            .'<h3>Pobierz archiwum bez zamykania turnusu</h3>'
            .'<p>Tworzy aktualną paczkę ZIP z dokumentami, eksportami, KSeF i zrzutem SQL, a następnie od razu ją pobiera. Status turnusu pozostaje bez zmian.</p>'
            .'<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'
            .'<input type="hidden" name="action" value="'.esc_attr(self::DOWNLOAD_LIVE_ACTION).'">'
            .'<input type="hidden" name="camp_id" value="'.$campId.'">'
            .wp_nonce_field(self::DOWNLOAD_LIVE_ACTION.'_'.$campId, '_wpnonce', true, false)
            .'<button class="button button-primary">Pobierz archiwum</button>'
            .'</form></div>';

        echo '<script>(function(){document.addEventListener("DOMContentLoaded",function(){'
            .'var panels=document.querySelectorAll(".wrap.bcs-admin > section.bcs-panel");'
            .'if(!panels.length)return;var panel=panels[panels.length-1];'
            .'var head=panel.querySelector(".bcs-panel-head");if(!head)return;'
            .'if(panel.querySelector(".bcs-download-live-archive-109"))return;'
            .'head.insertAdjacentHTML("afterend",'.wp_json_encode($html).');'
            .'});})();</script>';
    }

    public static function handle_download_live(): void {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        $campId = absint($_POST['camp_id'] ?? 0);
        check_admin_referer(self::DOWNLOAD_LIVE_ACTION.'_'.$campId);
        if (!$campId) wp_die('Nieprawidłowy turnus.');

        BCS_Release_108::ensure_schema();
        global $wpdb;
        $table = BCS_DB::table('camps');
        $snapshot = $wpdb->get_row($wpdb->prepare(
            "SELECT status,archived_at,archived_by,archive_path,archive_hash,archive_status,archive_size,archive_manifest,archive_created_at,updated_at FROM {$table} WHERE id=%d",
            $campId
        ), ARRAY_A);
        if (!is_array($snapshot)) wp_die('Nie znaleziono turnusu.');
        if ((string)($snapshot['status'] ?? '') === 'archived') {
            wp_safe_redirect(add_query_arg(['page'=>'bcs-camp-archive','camp_id'=>$campId], admin_url('admin.php')));
            exit;
        }

        $logsTable = BCS_DB::table('logs');
        $logBefore = (int)$wpdb->get_var("SELECT COALESCE(MAX(id),0) FROM {$logsTable}");
        $result = BCS_Release_108::build_archive($campId);

        // Przywracamy turnus dokładnie do stanu sprzed utworzenia tymczasowej paczki.
        $wpdb->update($table, $snapshot, ['id'=>$campId]);
        self::remove_false_archive_log($campId, $logBefore);

        if (empty($result['success']) || empty($result['path']) || !is_file((string)$result['path'])) {
            $message = (string)($result['message'] ?? 'Nie udało się utworzyć paczki archiwalnej.');
            wp_safe_redirect(add_query_arg([
                'page'=>'bcs-camp-archive',
                'camp_id'=>$campId,
                'archive_error'=>$message,
            ], admin_url('admin.php')));
            exit;
        }

        $path = (string)$result['path'];
        $hash = (string)($result['hash'] ?? (hash_file('sha256', $path) ?: ''));
        BCS_Utils::log('camp_archive_download_without_closing', [
            'camp_id'=>$campId,
            'archive_hash'=>$hash,
            'archive_size'=>(int)filesize($path),
            'turnus_status_zachowany'=>(string)$snapshot['status'],
        ], null, null);

        // Nie zostawiamy tymczasowych eksportów na serwerze. Jeżeli wyjątkowo
        // ścieżka pokrywa się ze wcześniej zapisanym archiwum, nie usuwamy go.
        $oldPath = (string)($snapshot['archive_path'] ?? '');
        if ($oldPath === '' || $oldPath !== $path) {
            register_shutdown_function(static function() use ($path): void {
                if (is_file($path)) @unlink($path);
            });
        }

        while (ob_get_level() > 0) @ob_end_clean();
        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="'.str_replace('"', '', basename($path)).'"');
        header('Content-Length: '.(string)filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    private static function remove_false_archive_log(int $campId, int $afterId): void {
        global $wpdb;
        $table = BCS_DB::table('logs');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,event_data FROM {$table} WHERE id>%d AND event_type='camp_archived' ORDER BY id",
            $afterId
        ));
        foreach ((array)$rows as $row) {
            $data = json_decode((string)$row->event_data, true);
            if (!is_array($data) || (int)($data['camp_id'] ?? 0) !== $campId) continue;
            $wpdb->delete($table, ['id'=>(int)$row->id]);
        }
    }
}
