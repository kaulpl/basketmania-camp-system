<?php
if (!defined('ABSPATH')) exit;

/** Uproszczony start rejestracji: bez ręcznej akceptacji administratora. */
final class BCS_Workflow_Modernization {
    private const MIGRATION_KEY = 'bcs_workflow_modernization_0186';

    public static function init(): void {
        add_action('init', [self::class, 'migrate_templates'], 2);
        add_action('template_redirect', [self::class, 'activate_registration_access'], 1);
        add_action('admin_footer', [self::class, 'remove_obsolete_admin_actions'], 99);
    }

    public static function migrate_templates(): void {
        if (get_option(self::MIGRATION_KEY)) return;
        $saved = get_option('bcs_content_templates', []);
        if (!is_array($saved)) $saved = [];
        $saved['emails']['registration_received'] = [
            'name' => 'Wstępna rejestracja i Formularz Obozowy',
            'subject' => 'Dziękujemy za zgłoszenie – {{CAMP_NAME}}',
            'body' => 'Dzień dobry {{PARENT_NAME}},<br><br>dziękujemy za wstępne zgłoszenie uczestnika <strong>{{CHILD_NAME}}</strong> na turnus <strong>{{CAMP_NAME}}</strong>.<br><br>Aby dokończyć proces rejestracji, prosimy o uzupełnienie Formularza Obozowego. Formularz jest dostępny od razu — nie wymaga wcześniejszej akceptacji administratora.<br><br><a href="{{PORTAL_URL}}" style="display:inline-block;background:#f97316;color:#ffffff;padding:13px 20px;border-radius:8px;text-decoration:none;font-weight:700">Uzupełnij Formularz Obozowy</a><br><br>Termin: {{CAMP_DATES}}<br>Miejsce: {{CAMP_LOCATION}}<br><br>Pozdrawiamy<br>Basketmania Camp',
            'sms' => 'Basketmania Camp: dziekujemy za zgloszenie {{CHILD_NAME}}. Formularz Obozowy jest dostepny od razu. Szczegoly wyslalismy e-mailem.',
        ];
        update_option('bcs_content_templates', $saved, false);
        update_option(self::MIGRATION_KEY, 1, false);
    }

    public static function activate_registration_access(): void {
        global $wpdb;
        $id = absint($_GET['bcs_registered'] ?? 0);
        $token = sanitize_text_field(wp_unslash($_GET['token'] ?? ''));
        if (!$id && $token !== '') {
            $id = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT id FROM ".BCS_DB::table('registrations')." WHERE public_token=%s LIMIT 1",
                $token
            ));
        }
        if (!$id) return;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM ".BCS_DB::table('registrations')." WHERE id=%d",
            $id
        ));
        if (!$row || $row->status === 'cancelled' || !empty($row->admin_confirmed_at)) return;

        $now = BCS_Utils::now();
        $wpdb->update(BCS_DB::table('registrations'), [
            'status' => 'admin_confirmed',
            'admin_confirmed_at' => $now,
            'admin_confirmed_by' => 0,
            'agreement_status' => $row->agreement_status ?: 'draft',
            'updated_at' => $now,
        ], ['id' => $id]);
        BCS_Utils::log('registration_auto_opened', [
            'reason' => 'Formularz Obozowy dostępny bez akceptacji administratora',
            'source' => $token !== '' ? 'parent_portal' : 'registration_redirect',
        ], $id, null);
    }

    public static function remove_obsolete_admin_actions(): void {
        if (!current_user_can('manage_options')) return;
        $page = sanitize_key(wp_unslash($_GET['page'] ?? ''));
        if (!in_array($page, ['bcs-registrations','bcs-dashboard','bcs-feedback'], true)) return;
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('a[href*="workflow=confirm_registration"], input[value="confirm_registration"]').forEach(function(el){
                const form = el.closest('form');
                const action = el.closest('.bcs-action, .bcs-icon-actions, .bcs-row-actions');
                if (form) form.remove(); else if (action && action.children.length === 1) action.remove(); else el.remove();
            });
            document.querySelectorAll('button, a, option').forEach(function(el){
                const text = (el.textContent || '').trim().toLowerCase();
                if (text.includes('potwierdź rejestrację') || text.includes('zaakceptuj rejestrację') || text.includes('wyślij formularz po akceptacji')) {
                    const form = el.closest('form');
                    if (form) form.remove(); else el.remove();
                }
            });
            document.querySelectorAll('.bcs-feedback-table tbody tr').forEach(function(row){
                const status = row.querySelector('.bcs-feedback-status.status-resolved');
                if (!status) return;
                const actions = row.querySelector('.bcs-feedback-row-actions');
                if (actions) actions.innerHTML = '<span class="bcs-muted">Brak dostępnych akcji</span>';
            });
        });
        </script>
        <?php
    }
}
