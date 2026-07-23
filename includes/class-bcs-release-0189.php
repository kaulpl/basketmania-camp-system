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
        add_action('template_redirect', [self::class, 'render_standalone_parent_portal'], 0);
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

    /**
     * Panel Rodzica działa jako samodzielny widok marki, bez nagłówka i stopki motywu WordPress.
     */
    public static function render_standalone_parent_portal(): void {
        if (is_admin() || wp_doing_ajax() || is_feed() || is_embed()) return;
        $post = get_queried_object();
        if (!$post instanceof WP_Post || !has_shortcode((string)$post->post_content, 'basketmania_portal')) return;

        status_header(200);
        nocache_headers();
        $settings = get_option('bcs_settings', []);
        $company = trim((string)($settings['company_name'] ?? 'Basketmania Camp')) ?: 'Basketmania Camp';
        $email = sanitize_email((string)($settings['company_email'] ?? get_option('admin_email')));
        $brand_url = esc_url((string)($settings['portal_brand_url'] ?? 'https://camp.basketmania.pl/'));
        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex,nofollow,noarchive">
            <title><?php echo esc_html('Panel Rodzica — '.$company); ?></title>
            <?php wp_head(); ?>
            <style>
                html,body{margin:0!important;padding:0!important;background:#f3f4f6}
                body.admin-bar{margin-top:0!important}
                #wpadminbar{display:none!important}
                .bcs-parent-standalone{min-height:100vh;display:flex;flex-direction:column}
                .bcs-parent-standalone__main{flex:1}
                .bcs-parent-footer{margin-top:42px;background:#161616;color:#fff;text-align:center;padding:28px 20px;font-family:Arial,sans-serif}
                .bcs-parent-footer strong{display:block;font-size:17px;margin-bottom:7px}
                .bcs-parent-footer p{margin:4px 0;color:#d1d5db;font-size:13px;line-height:1.55}
                .bcs-parent-footer a{color:#fb923c;text-decoration:none;font-weight:700}
                .bcs-parent-footer__line{width:46px;height:3px;background:#f97316;border-radius:99px;margin:0 auto 16px}
            </style>
        </head>
        <body <?php body_class('bcs-parent-portal-page'); ?>>
        <?php wp_body_open(); ?>
        <div class="bcs-parent-standalone">
            <main class="bcs-parent-standalone__main">
                <?php echo do_shortcode('[basketmania_portal]'); ?>
            </main>
            <footer class="bcs-parent-footer">
                <div class="bcs-parent-footer__line"></div>
                <strong><?php echo esc_html($company); ?></strong>
                <p>Profesjonalne obozy koszykarskie, rozwój i sportowe emocje.</p>
                <?php if ($email !== ''): ?><p><a href="mailto:<?php echo esc_attr($email); ?>"><?php echo esc_html($email); ?></a></p><?php endif; ?>
                <?php if ($brand_url !== ''): ?><p><a href="<?php echo esc_url($brand_url); ?>" target="_blank" rel="noopener noreferrer">camp.basketmania.pl</a></p><?php endif; ?>
            </footer>
        </div>
        <?php wp_footer(); ?>
        </body>
        </html>
        <?php
        exit;
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
