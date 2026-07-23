<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_0192 {
    public static function init(): void {
        add_action('admin_footer', [self::class, 'crm_history_patch'], 400);
    }

    public static function crm_history_patch(): void {
        if (!is_admin() || !current_user_can('manage_options')) return;
        $page = sanitize_key(wp_unslash($_GET['page'] ?? ''));
        $registration_id = absint($_GET['view'] ?? 0);
        if ($page !== 'bcs-registrations' || !$registration_id) return;

        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT event_type, created_at FROM " . BCS_DB::table('logs') . " WHERE registration_id=%d AND event_type IN ('agreement_template_opened','agreement_opened_for_signature') ORDER BY created_at ASC, id ASC",
            $registration_id
        ));

        $events = [];
        foreach ((array) $rows as $row) {
            $label = $row->event_type === 'agreement_template_opened'
                ? 'Rodzic otworzył wzór umowy'
                : 'Rodzic po raz pierwszy otworzył umowę do podpisu';
            $events[] = [
                'event' => (string) $row->event_type,
                'label' => $label,
                'meta' => BCS_Utils::format_datetime((string) $row->created_at) . ' · Rodzic',
            ];
        }
        ?>
        <script>
        (function () {
            const replacements = new Map([
                ['Wysłano umowę do akceptacji', 'Wysłano umowę do podpisu'],
                ['Wyslano umowe do akceptacji', 'Wysłano umowę do podpisu']
            ]);
            const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
            let node;
            while ((node = walker.nextNode())) {
                const value = (node.nodeValue || '').trim();
                if (replacements.has(value)) node.nodeValue = node.nodeValue.replace(value, replacements.get(value));
            }

            const timeline = document.querySelector('.bcs-timeline');
            if (!timeline) return;
            timeline.querySelectorAll('.bcs-timeline-item').forEach(function (item) {
                const title = item.querySelector('strong');
                if (title && title.textContent.trim() === '') item.remove();
            });
            const existing = timeline.textContent || '';
            const events = <?php echo wp_json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            events.forEach(function (event) {
                if (!event.label || existing.includes(event.label)) return;
                const item = document.createElement('div');
                item.className = 'bcs-timeline-item bcs-timeline-item-parent';
                item.dataset.eventType = event.event;
                item.innerHTML = '<span class="bcs-timeline-dot"></span><div><strong></strong><small></small></div>';
                item.querySelector('strong').textContent = event.label;
                item.querySelector('small').textContent = event.meta;
                timeline.appendChild(item);
            });
        })();
        </script>
        <?php
    }
}
