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
        add_action('admin_head', [self::class, 'admin_styles'], 200);
        add_action('admin_footer', [self::class, 'admin_ui_enhancements'], 250);
        add_action('wp_head', [self::class, 'parent_portal_styles'], 200);
        add_action('wp_footer', [self::class, 'parent_portal_enhancements'], 200);
    }

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

    /** Listy obozowe obejmują tylko uczestników z podpisaną umową i pełną wpłatą. */
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

    private static function is_plugin_admin_page(): bool {
        if (!is_admin()) return false;
        $page = sanitize_key(wp_unslash($_GET['page'] ?? ''));
        return $page !== '' && str_starts_with($page, 'bcs-');
    }

    /** Jednolity wygląd przełączników i sekcji ustawień w całym panelu wtyczki. */
    public static function admin_styles(): void {
        if (!self::is_plugin_admin_page()) return;
        ?>
        <style>
            .bcs-admin input[type="checkbox"]{
                -webkit-appearance:none!important;appearance:none!important;width:44px!important;height:24px!important;
                min-width:44px!important;margin:0 9px 0 0!important;border:2px solid #d1d5db!important;border-radius:999px!important;
                background:#fff!important;box-shadow:none!important;position:relative!important;vertical-align:middle!important;
                cursor:pointer!important;transition:background .18s ease,border-color .18s ease!important;
            }
            .bcs-admin input[type="checkbox"]:before{
                content:""!important;position:absolute!important;width:16px!important;height:16px!important;left:2px!important;top:2px!important;
                margin:0!important;border:0!important;border-radius:50%!important;background:#d1d5db!important;
                transform:none!important;transition:transform .18s ease,background .18s ease!important;
            }
            .bcs-admin input[type="checkbox"]:checked{background:#22c55e!important;border-color:#22c55e!important}
            .bcs-admin input[type="checkbox"]:checked:before{background:#fff!important;transform:translateX(20px)!important}
            .bcs-admin input[type="checkbox"]:focus{outline:2px solid #fdba74!important;outline-offset:2px!important}
            .bcs-admin input[type="checkbox"]:disabled{opacity:.55!important;cursor:not-allowed!important}
            .bcs-settings-section-0187,.bcs-settings-accordion,.bcs-notifications-accordion-0189{
                margin:16px 0!important;border:1px solid #dcdcde!important;border-radius:10px!important;background:#fff!important;overflow:hidden!important;
                box-shadow:0 1px 2px rgba(15,23,42,.04)!important;
            }
            .bcs-settings-section-0187>.bcs-settings-toggle-0187,
            .bcs-settings-accordion>summary,.bcs-notifications-accordion-0189>summary{
                min-height:62px!important;padding:15px 18px!important;background:#fff!important;display:flex!important;align-items:center!important;
                justify-content:space-between!important;gap:14px!important;cursor:pointer!important;list-style:none!important;border:0!important;width:100%!important;
                box-sizing:border-box!important;font-size:15px!important;color:#1d2327!important;
            }
            .bcs-settings-accordion>summary::-webkit-details-marker,.bcs-notifications-accordion-0189>summary::-webkit-details-marker{display:none!important}
            .bcs-settings-accordion>summary>span:first-child,.bcs-notifications-accordion-0189>summary>span:first-child{display:flex!important;align-items:center!important;gap:10px!important}
            .bcs-settings-accordion>summary:after,.bcs-notifications-accordion-0189>summary:after{content:"⌄";font-size:22px;color:#646970;transition:transform .18s ease}
            .bcs-settings-accordion[open]>summary:after,.bcs-notifications-accordion-0189[open]>summary:after{transform:rotate(180deg)}
            .bcs-settings-accordion-body,.bcs-notifications-accordion-0189__body{padding:4px 18px 20px!important;border-top:1px solid #f0f0f1!important}
            .bcs-settings-section-0187>.bcs-settings-content-0187{padding:4px 18px 20px!important;border-top:1px solid #f0f0f1!important}
        </style>
        <?php
    }

    public static function admin_ui_enhancements(): void {
        if (!self::is_plugin_admin_page()) return;
        $page = sanitize_key(wp_unslash($_GET['page'] ?? ''));
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

            function makeDetails(title, icon, bodyNode, cls) {
                const details = document.createElement('details');
                details.className = cls || 'bcs-settings-accordion';
                const summary = document.createElement('summary');
                summary.innerHTML = '<span><span class="dashicons '+icon+'"></span><strong>'+title+'</strong></span>';
                const body = document.createElement('div');
                body.className = cls === 'bcs-notifications-accordion-0189' ? 'bcs-notifications-accordion-0189__body' : 'bcs-settings-accordion-body';
                body.appendChild(bodyNode);
                details.appendChild(summary);
                details.appendChild(body);
                return details;
            }

            function normalizeSettings() {
                if (<?php echo wp_json_encode($page); ?> !== 'bcs-settings') return;
                const wrap = document.querySelector('.wrap.bcs-admin');
                if (!wrap) return;

                const oldWrapper = wrap.querySelector('.bcs-settings-section-0187');
                if (oldWrapper) {
                    const form = oldWrapper.querySelector('form');
                    if (form) {
                        oldWrapper.parentNode.insertBefore(form, oldWrapper);
                        oldWrapper.remove();
                    }
                }

                const form = Array.from(wrap.querySelectorAll('form')).find(function (item) {
                    return item.querySelector('[name="bcs_save_settings"]');
                });
                if (form && !form.dataset.bcsGeneralSplit0189) {
                    form.dataset.bcsGeneralSplit0189 = '1';
                    const firstSeparate = Array.from(form.children).find(function (node) {
                        return node.matches && (node.matches('details.bcs-settings-accordion') || node.matches('.bcs-settings-section-0187'));
                    });
                    if (firstSeparate) {
                        const holder = document.createElement('div');
                        const movable = [];
                        let node = form.firstChild;
                        while (node && node !== firstSeparate) {
                            const next = node.nextSibling;
                            movable.push(node);
                            node = next;
                        }
                        movable.forEach(function (item) { holder.appendChild(item); });
                        if (holder.textContent.trim() !== '' || holder.querySelector('input,select,textarea')) {
                            form.insertBefore(makeDetails('Ustawienia ogólne', 'dashicons-admin-generic', holder), firstSeparate);
                        }
                    }
                }

                const notification = wrap.querySelector('.bcs-notification-settings');
                if (notification && !notification.closest('.bcs-notifications-accordion-0189')) {
                    notification.parentNode.insertBefore(makeDetails('Powiadomienia workflow', 'dashicons-bell', notification, 'bcs-notifications-accordion-0189'), notification);
                }
            }

            removeOldAction();
            normalizeSettings();
            new MutationObserver(function () {
                removeOldAction();
                normalizeSettings();
            }).observe(document.body, {childList:true, subtree:true});
        });
        </script>
        <?php
    }

    private static function is_parent_portal_request(): bool {
        if (is_admin() || wp_doing_ajax()) return false;
        $post = get_queried_object();
        return $post instanceof WP_Post && has_shortcode((string)$post->post_content, 'basketmania_portal');
    }

    public static function parent_portal_styles(): void {
        if (!self::is_parent_portal_request()) return;
        ?>
        <style>
            .bcs-wrap input[type="checkbox"]{-webkit-appearance:none!important;appearance:none!important;width:48px!important;height:26px!important;min-width:48px!important;border:2px solid #d1d5db!important;border-radius:999px!important;background:#fff!important;position:relative!important;box-shadow:none!important;margin:0 10px 0 0!important;cursor:pointer!important;transition:.18s ease!important}
            .bcs-wrap input[type="checkbox"]:before{content:""!important;position:absolute!important;width:18px!important;height:18px!important;left:2px!important;top:2px!important;border-radius:50%!important;background:#d1d5db!important;transition:.18s ease!important}
            .bcs-wrap input[type="checkbox"]:checked{background:#22c55e!important;border-color:#22c55e!important}
            .bcs-wrap input[type="checkbox"]:checked:before{background:#fff!important;transform:translateX(22px)!important}
            .bcs-form-section>h3{display:flex!important;align-items:center!important;gap:10px!important;margin:0 0 18px!important;padding:12px 15px!important;background:#fff7ed!important;border-left:5px solid #f97316!important;border-radius:8px!important;color:#9a3412!important;font-size:18px!important}
            .bcs-form-section>h3:before{content:"";width:9px;height:9px;border-radius:50%;background:#f97316;box-shadow:0 0 0 4px #ffedd5}
            .bcs-parent-main-title{text-align:center!important;color:#fff!important;font-size:30px!important;line-height:1.15!important;margin:0 0 18px!important;font-weight:800!important;letter-spacing:-.02em!important}
            .bcs-agreement-year-note{display:block!important;margin-top:7px!important;color:#9a3412!important;background:#fff7ed!important;border-radius:6px!important;padding:7px 9px!important;font-size:12px!important;line-height:1.4!important}
        </style>
        <?php
    }

    private static function parent_portal_camp_year(): int {
        $token = sanitize_text_field(wp_unslash($_GET['token'] ?? ''));
        if ($token === '') return 0;
        global $wpdb;
        $date = (string)$wpdb->get_var($wpdb->prepare(
            "SELECT c.start_date FROM ".BCS_DB::table('registrations')." r JOIN ".BCS_DB::table('camps')." c ON c.id=r.camp_id WHERE r.public_token=%s LIMIT 1",
            $token
        ));
        return (int)substr($date, 0, 4);
    }

    public static function parent_portal_enhancements(): void {
        if (!self::is_parent_portal_request()) return;
        $camp_year = self::parent_portal_camp_year();
        $current_year = (int)current_time('Y');
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            function normalized(el) { return ((el && el.textContent) || '').replace(/\s+/g,' ').trim().toLowerCase(); }

            document.querySelectorAll('body *').forEach(function (el) {
                const text = normalized(el);
                if (text === 'bezpieczny dostęp' || text === 'bezpieczny dostep') {
                    const box = el.closest('.bcs-secure-access,.bcs-secure,.bcs-badge,.bcs-pill') || el;
                    box.remove();
                }
                if (text.includes('wersja testowa systemu')) {
                    const ownText = Array.from(el.children || []).length === 0;
                    if (ownText) el.textContent = 'Wersja testowa systemu';
                }
            });

            const kicker = Array.from(document.querySelectorAll('body *')).find(function (el) {
                return normalized(el) === 'strefa uczestnika';
            });
            if (kicker) {
                const header = kicker.closest('.bcs-portal-header,.bcs-topbar,.bcs-brand-bar,.bcs-hero-brand') || kicker.parentElement;
                if (header) header.remove();
            }

            let hero = document.querySelector('.bcs-hero,.bcs-portal-hero,.bcs-camp-summary,.bcs-portal-summary');
            if (!hero) {
                hero = Array.from(document.querySelectorAll('.bcs-card,section,header')).find(function (el) {
                    const text = normalized(el);
                    return text.includes('turnus') && (text.includes('miejsce') || text.includes('termin'));
                });
            }
            if (hero && !hero.querySelector('.bcs-parent-main-title')) {
                const title = document.createElement('h1');
                title.className = 'bcs-parent-main-title';
                title.textContent = 'Panel Rodzica';
                hero.insertBefore(title, hero.firstChild);
            }

            const campYear = <?php echo (int)$camp_year; ?>;
            const currentYear = <?php echo (int)$current_year; ?>;
            if (campYear > currentYear) {
                document.querySelectorAll('.bcs-step').forEach(function (step) {
                    const label = step.querySelector('strong');
                    if (!label || normalized(label) !== 'umowa' || step.querySelector('.bcs-agreement-year-note')) return;
                    const note = document.createElement('small');
                    note.className = 'bcs-agreement-year-note';
                    note.textContent = 'Umowy będą wysyłane od początku stycznia roku, w którym odbywa się obóz.';
                    const copy = step.querySelector('.bcs-step-copy') || step;
                    copy.appendChild(note);
                });
            }
        });
        </script>
        <?php
    }

    /** Panel Rodzica bez nagłówka i stopki aktywnego motywu WordPress. */
    public static function render_standalone_parent_portal(): void {
        if (!self::is_parent_portal_request() || is_feed() || is_embed()) return;
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
                body.admin-bar{margin-top:0!important}#wpadminbar{display:none!important}
                .bcs-parent-standalone{min-height:100vh;display:flex;flex-direction:column}.bcs-parent-standalone__main{flex:1}
                .bcs-parent-footer{margin-top:42px;background:#161616;color:#fff;text-align:center;padding:28px 20px;font-family:Arial,sans-serif}
                .bcs-parent-footer strong{display:block;font-size:17px;margin-bottom:7px}.bcs-parent-footer p{margin:4px 0;color:#d1d5db;font-size:13px;line-height:1.55}
                .bcs-parent-footer a{color:#fb923c;text-decoration:none;font-weight:700}.bcs-parent-footer__line{width:46px;height:3px;background:#f97316;border-radius:99px;margin:0 auto 16px}
            </style>
        </head>
        <body <?php body_class('bcs-parent-portal-page'); ?>>
        <?php wp_body_open(); ?>
        <div class="bcs-parent-standalone">
            <main class="bcs-parent-standalone__main"><?php echo do_shortcode('[basketmania_portal]'); ?></main>
            <footer class="bcs-parent-footer">
                <div class="bcs-parent-footer__line"></div><strong><?php echo esc_html($company); ?></strong>
                <p>Profesjonalne obozy koszykarskie, rozwój i sportowe emocje.</p>
                <?php if ($email !== ''): ?><p><a href="mailto:<?php echo esc_attr($email); ?>"><?php echo esc_html($email); ?></a></p><?php endif; ?>
                <?php if ($brand_url !== ''): ?><p><a href="<?php echo esc_url($brand_url); ?>" target="_blank" rel="noopener noreferrer">camp.basketmania.pl</a></p><?php endif; ?>
            </footer>
        </div>
        <?php wp_footer(); ?>
        </body></html>
        <?php
        exit;
    }
}
