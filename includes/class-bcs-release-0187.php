<?php
if (!defined('ABSPATH')) exit;

/** Zbiorcze poprawki interfejsu i limitów dla wydania 0.18.7. */
final class BCS_Release_0187 {
    private static bool $capacity_filter_active = false;

    public static function init(): void {
        add_action('init', [self::class, 'override_signup_shortcode'], 30);
        add_action('admin_post_nopriv_bcs_signup', [self::class, 'allow_over_capacity_signup'], 1);
        add_action('admin_post_bcs_signup', [self::class, 'allow_over_capacity_signup'], 1);
        add_action('admin_footer', [self::class, 'admin_enhancements'], 120);
    }

    /**
     * Formularz nadal pokazuje wszystkie otwarte turnusy, również po osiągnięciu limitu.
     * Limit pozostaje wartością informacyjną i nie blokuje przyjmowania zgłoszeń.
     */
    public static function override_signup_shortcode(): void {
        if (!shortcode_exists('basketmania_signup')) return;
        remove_shortcode('basketmania_signup');
        add_shortcode('basketmania_signup', [self::class, 'signup_shortcode']);
    }

    public static function signup_shortcode(): string {
        add_filter('query', [self::class, 'signup_list_query'], 999);
        try {
            return BCS_Frontend::signup_shortcode();
        } finally {
            remove_filter('query', [self::class, 'signup_list_query'], 999);
        }
    }

    public static function signup_list_query(string $query): string {
        $registrations = preg_quote(BCS_DB::table('registrations'), '~');
        $pattern = "~\\(SELECT COUNT\\(\\*\\) FROM {$registrations} r WHERE r\\.camp_id=c\\.id AND r\\.status<>'cancelled'\\) registered~";
        return (string)preg_replace($pattern, '0 registered', $query, 1);
    }

    public static function allow_over_capacity_signup(): void {
        if (self::$capacity_filter_active) return;
        self::$capacity_filter_active = true;
        add_filter('query', [self::class, 'capacity_query'], 999);
        add_action('shutdown', [self::class, 'remove_capacity_filter'], 1);
    }

    public static function remove_capacity_filter(): void {
        remove_filter('query', [self::class, 'capacity_query'], 999);
        self::$capacity_filter_active = false;
    }

    public static function capacity_query(string $query): string {
        $table = preg_quote(BCS_DB::table('camps'), '~');
        $pattern = "~SELECT \\* FROM {$table} WHERE id=(%d|[0-9]+) AND status='open'~";
        if (!preg_match($pattern, $query)) return $query;
        return (string)preg_replace(
            $pattern,
            "SELECT c.*, 0 AS capacity FROM ".BCS_DB::table('camps')." c WHERE c.id=$1 AND c.status='open'",
            $query,
            1
        );
    }

    public static function admin_enhancements(): void {
        if (!current_user_can('manage_options')) return;
        $page = sanitize_key(wp_unslash($_GET['page'] ?? ''));
        if (!in_array($page, ['bcs-dashboard', 'bcs-camps', 'bcs-registrations', 'bcs-settings'], true)) return;

        $camp_stats = [];
        if (in_array($page, ['bcs-dashboard', 'bcs-camps'], true)) {
            global $wpdb;
            $rows = $wpdb->get_results(
                "SELECT c.id,c.capacity,
                    SUM(CASE WHEN r.id IS NOT NULL THEN 1 ELSE 0 END) active_count,
                    SUM(CASE WHEN r.id IS NOT NULL AND r.total_amount>0 AND r.paid_amount>=r.total_amount THEN 1 ELSE 0 END) paid_count
                 FROM ".BCS_DB::table('camps')." c
                 LEFT JOIN ".BCS_DB::table('registrations')." r ON r.camp_id=c.id AND r.status<>'cancelled'
                 GROUP BY c.id,c.capacity"
            );
            foreach ($rows as $row) {
                $camp_stats[(string)(int)$row->id] = [
                    'capacity' => max(0, (int)$row->capacity),
                    'active' => max(0, (int)$row->active_count),
                    'paid' => max(0, (int)$row->paid_count),
                ];
            }
        }
        ?>
        <style>
            .bcs-progress.bcs-capacity-progress{display:flex;overflow:hidden;background:#e5e7eb}
            .bcs-progress.bcs-capacity-progress>span{display:block;height:100%;position:static}
            .bcs-progress .bcs-capacity-paid{background:#f97316!important}
            .bcs-progress .bcs-capacity-pending{background:#9ca3af!important}
            .bcs-capacity-legend{display:flex;gap:14px;flex-wrap:wrap;margin-top:7px;font-size:12px;color:#646970}
            .bcs-capacity-legend span{display:inline-flex;align-items:center;gap:5px}
            .bcs-capacity-legend i{width:9px;height:9px;border-radius:50%;display:inline-block}
            .bcs-capacity-legend .is-paid i{background:#f97316}
            .bcs-capacity-legend .is-pending i{background:#9ca3af}
            .bcs-settings-section-0187{margin:18px 0;border:1px solid #c3c4c7;border-radius:8px;background:#fff;overflow:hidden}
            .bcs-settings-section-0187>.bcs-settings-toggle-0187{width:100%;display:flex;align-items:center;justify-content:space-between;gap:14px;padding:16px 18px;border:0;background:#fff;cursor:pointer;text-align:left;font-size:15px}
            .bcs-settings-section-0187>.bcs-settings-toggle-0187 strong{font-size:16px}
            .bcs-settings-section-0187>.bcs-settings-toggle-0187 .dashicons{transition:transform .18s ease}
            .bcs-settings-section-0187.is-open>.bcs-settings-toggle-0187 .dashicons{transform:rotate(180deg)}
            .bcs-settings-section-0187>.bcs-settings-content-0187{display:none;padding:0 18px 18px}
            .bcs-settings-section-0187.is-open>.bcs-settings-content-0187{display:block}
        </style>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const page = <?php echo wp_json_encode($page); ?>;
            const stats = <?php echo wp_json_encode($camp_stats); ?>;

            function removeObsoleteConfirmation() {
                document.querySelectorAll('button, a, input[type="submit"], input[type="button"]').forEach(function (el) {
                    const text = ((el.textContent || el.value || '') + '').toLowerCase().replace(/[–—]/g, '-').replace(/\s+/g, ' ').trim();
                    if (text.includes('rejestracja - potwierdzono') || text.includes('rejestracja: potwierdzono')) {
                        const form = el.closest('form');
                        if (form) form.remove(); else el.remove();
                    }
                });
            }

            function applyCapacityBars() {
                if (!['bcs-dashboard','bcs-camps'].includes(page)) return;
                document.querySelectorAll('.bcs-camp-card, .bcs-list-card').forEach(function (card) {
                    const idNode = card.querySelector('.bcs-id');
                    const match = idNode && idNode.textContent.match(/#(\d+)/);
                    if (!match || !stats[match[1]]) return;
                    const data = stats[match[1]];
                    const bar = card.querySelector('.bcs-progress');
                    if (!bar) return;
                    const capacity = Math.max(1, Number(data.capacity || 0));
                    const paid = Math.max(0, Number(data.paid || 0));
                    const active = Math.max(paid, Number(data.active || 0));
                    const paidWidth = Math.min(100, paid / capacity * 100);
                    const pendingWidth = Math.min(100 - paidWidth, Math.max(0, active - paid) / capacity * 100);
                    bar.classList.add('bcs-capacity-progress');
                    bar.innerHTML = '<span class="bcs-capacity-paid" style="width:'+paidWidth+'%"></span><span class="bcs-capacity-pending" style="width:'+pendingWidth+'%"></span>';
                    if (!card.querySelector('.bcs-capacity-legend')) {
                        const legend = document.createElement('div');
                        legend.className = 'bcs-capacity-legend';
                        legend.innerHTML = '<span class="is-paid"><i></i>Potwierdzone wpłaty: <strong>'+paid+'</strong></span><span class="is-pending"><i></i>Rozpoczęte, nieanulowane bez pełnej wpłaty: <strong>'+Math.max(0,active-paid)+'</strong></span>';
                        bar.insertAdjacentElement('afterend', legend);
                    }
                });
            }

            function makeAccordion(target, key, title) {
                if (!target || target.dataset.bcsAccordion0187) return;
                target.dataset.bcsAccordion0187 = '1';
                const wrapper = document.createElement('section');
                wrapper.className = 'bcs-settings-section-0187';
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'bcs-settings-toggle-0187';
                button.innerHTML = '<strong>'+title+'</strong><span class="dashicons dashicons-arrow-down-alt2"></span>';
                const content = document.createElement('div');
                content.className = 'bcs-settings-content-0187';
                target.parentNode.insertBefore(wrapper, target);
                wrapper.appendChild(button);
                wrapper.appendChild(content);
                content.appendChild(target);
                const storageKey = 'bcs_settings_accordion_0187_'+key;
                const open = localStorage.getItem(storageKey) === '1';
                wrapper.classList.toggle('is-open', open);
                button.setAttribute('aria-expanded', open ? 'true' : 'false');
                button.addEventListener('click', function () {
                    const next = !wrapper.classList.contains('is-open');
                    wrapper.classList.toggle('is-open', next);
                    button.setAttribute('aria-expanded', next ? 'true' : 'false');
                    localStorage.setItem(storageKey, next ? '1' : '0');
                });
            }

            function setupSettingsAccordions() {
                if (page !== 'bcs-settings') return;
                const wrap = document.querySelector('.wrap.bcs-admin');
                if (!wrap) return;
                if (wrap.dataset.bcsSettingsNative === '0209') return;

                const mainForm = Array.from(wrap.querySelectorAll('form')).find(function(form){
                    return form.querySelector('[name="bcs_save_settings"]');
                });
                if (mainForm) makeAccordion(mainForm, 'plugin', 'Ustawienia wtyczki');

                const notification = wrap.querySelector('.bcs-notification-settings');
                if (notification) makeAccordion(notification, 'notifications', 'Ustawienia powiadomień');

                Array.from(wrap.querySelectorAll('h2')).forEach(function(heading){
                    if ((heading.textContent || '').trim().toLowerCase() !== 'ustawienia dokumentów i automatyzacji') return;
                    if (heading.dataset.bcsDocuments0187) return;
                    heading.dataset.bcsDocuments0187 = '1';
                    const holder = document.createElement('div');
                    heading.parentNode.insertBefore(holder, heading);
                    holder.appendChild(heading);
                    let node = holder.nextSibling;
                    while (node) {
                        const next = node.nextSibling;
                        if (node.nodeType === 1 && (node.matches('h2') || node.matches('.bcs-form-actions'))) break;
                        holder.appendChild(node);
                        node = next;
                    }
                    makeAccordion(holder, 'documents', 'Ustawienia dokumentów i automatyzacji');
                });
            }

            removeObsoleteConfirmation();
            applyCapacityBars();
            setupSettingsAccordions();
            const observer = new MutationObserver(function(){
                removeObsoleteConfirmation();
                setupSettingsAccordions();
            });
            observer.observe(document.body, {childList:true, subtree:true});
        });
        </script>
        <?php
    }
}
