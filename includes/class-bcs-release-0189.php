<?php
if (!defined('ABSPATH')) exit;

/** Poprawki wydania 0.18.9. */
final class BCS_Release_0189 {
    private const MAIL_MIGRATION = 'bcs_release_0189_registration_mail_migrated';
    private static bool $report_filter_active = false;

    public static function init(): void {
        self::migrate_registration_email();
        add_action('admin_post_bcs_camp_shirts_pdf', [self::class, 'enable_confirmed_participants_filter'], 0);
        add_action('admin_post_bcs_camp_participants_pdf', [self::class, 'enable_confirmed_participants_filter'], 0);
        add_action('admin_footer', [self::class, 'remove_obsolete_registration_action'], 200);
    }

    /**
     * Aktualizuje wyłącznie poprzedni komunikat systemowy. Innych zmian administratora
     * w szablonie nie nadpisuje.
     */
    private static function migrate_registration_email(): void {
        if (get_option(self::MAIL_MIGRATION)) return;

        $saved = get_option('bcs_content_templates', []);
        if (!is_array($saved)) $saved = [];
        $template = (array)($saved['emails']['registration_received'] ?? []);
        $body = (string)($template['body'] ?? '');
        $old = 'Formularz jest dostępny od razu — nie wymaga wcześniejszej akceptacji administratora.';
        $new = 'Przejdź od razu do Panelu Rodzica i go wypełnij.';

        if ($body !== '' && str_contains($body, $old)) {
            $template['body'] = str_replace($old, $new, $body);
            $saved['emails']['registration_received'] = $template;
            update_option('bcs_content_templates', $saved, false);
        }

        update_option(self::MAIL_MIGRATION, 1, false);
    }

    /**
     * Listy obozowe obejmują tylko uczestników z podpisaną umową i pełną wpłatą.
     */
    public static function enable_confirmed_participants_filter(): void {
        if (self::$report_filter_active) return;
        self::$report_filter_active = true;
        add_filter('query', [self::class, 'filter_report_query'], 999);
        add_action('shutdown', [self::class, 'disable_confirmed_participants_filter'], 1);
    }

    public static function disable_confirmed_participants_filter(): void {
        remove_filter('query', [self::class, 'filter_report_query'], 999);
        self::$report_filter_active = false;
    }

    public static function filter_report_query(string $query): string {
        if (!self::$report_filter_active) return $query;
        $table = preg_quote(BCS_DB::table('registrations'), '~');
        if (!preg_match("~FROM {$table} WHERE camp_id=[0-9]+ AND status<>'cancelled' ORDER BY~", $query)) return $query;

        return (string)preg_replace(
            "~WHERE camp_id=([0-9]+) AND status<>'cancelled' ORDER BY~",
            "WHERE camp_id=$1 AND status<>'cancelled' AND agreement_status='accepted' AND total_amount>0 AND paid_amount>=total_amount ORDER BY",
            $query,
            1
        );
    }

    /** Usuwa pozostały status starego etapu z sekcji Szybkie czynności. */
    public static function remove_obsolete_registration_action(): void {
        if (!current_user_can('manage_options')) return;
        if (sanitize_key(wp_unslash($_GET['page'] ?? '')) !== 'bcs-registrations') return;
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            function removeOldAction() {
                document.querySelectorAll('.bcs-quick-actions .bcs-action-done, .bcs-quick-actions button, .bcs-quick-actions a').forEach(function (el) {
                    const text = ((el.textContent || el.value || '') + '').toLowerCase().replace(/[–—]/g, '-').replace(/\s+/g, ' ').trim();
                    if (text.includes('rejestracja potwierdzona - wykonano') || text.includes('rejestracja - potwierdzono') || text.includes('rejestracja: potwierdzono')) {
                        const form = el.closest('form');
                        if (form) form.remove(); else el.remove();
                    }
                });
            }
            removeOldAction();
            new MutationObserver(removeOldAction).observe(document.body, {childList:true, subtree:true});
        });
        </script>
        <?php
    }
}
