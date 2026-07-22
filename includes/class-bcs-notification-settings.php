<?php
if (!defined('ABSPATH')) exit;

/** Konfiguracja kanałów powiadomień dla poszczególnych etapów workflow. */
final class BCS_Notification_Settings {
    private const OPTION = 'bcs_notification_channels';

    public static function init(): void {
        add_action('admin_post_bcs_save_notification_settings', [self::class, 'save']);
        add_action('admin_footer', [self::class, 'render_settings_section']);
    }

    public static function events(): array {
        return [
            'registration_received' => ['label'=>'Wstępne zgłoszenie zapisane','description'=>'Podziękowanie i odnośnik do pełnego Formularza Obozowego.','default'=>'email','once'=>true],
            'camp_form_request' => ['label'=>'Prośba o uzupełnienie Formularza Obozowego','description'=>'Dodatkowe lub ręczne ponowienie prośby o formularz.','default'=>'off','once'=>false],
            'camp_form_verified' => ['label'=>'Formularz Obozowy sprawdzony','description'=>'Informacja o zakończeniu weryfikacji danych.','default'=>'email','once'=>true],
            'agreement_sent' => ['label'=>'Umowa gotowa do podpisania','description'=>'Powiadomienie z dostępem do podpisu kodem SMS.','default'=>'both','once'=>false],
            'agreement_signed' => ['label'=>'Umowa podpisana','description'=>'Potwierdzenie podpisania oraz dane do płatności. Wysyłane tylko raz.','default'=>'email','once'=>true],
            'stripe_link' => ['label'=>'Link do płatności Stripe','description'=>'Indywidualny link do płatności internetowej.','default'=>'email','once'=>false],
            'payment' => ['label'=>'Przypomnienie o płatności','description'=>'Automatyczne lub ręczne przypomnienie o zaległej wpłacie.','default'=>'both','once'=>false],
            'paid' => ['label'=>'Płatność zaksięgowana','description'=>'Potwierdzenie pełnego opłacenia udziału.','default'=>'email','once'=>true],
            'invoice_issued' => ['label'=>'Faktura wystawiona','description'=>'Wiadomość z fakturą i dostępem do dokumentu.','default'=>'email','once'=>true],
            'pre_camp' => ['label'=>'Informacje przed obozem','description'=>'Informacje organizacyjne wysyłane przed rozpoczęciem turnusu.','default'=>'email','once'=>false],
        ];
    }

    public static function channels_for(string $event, string $fallback='email'): string {
        $events = self::events();
        $saved = get_option(self::OPTION, []);
        $value = is_array($saved) ? (string)($saved[$event] ?? '') : '';
        if (!in_array($value, ['off','email','sms','both'], true)) {
            $value = (string)($events[$event]['default'] ?? $fallback);
        }
        return $value;
    }

    public static function is_once(string $event): bool {
        return !empty(self::events()[$event]['once']);
    }

    public static function already_sent(int $registration_id, string $event): bool {
        if (!self::is_once($event)) return false;
        global $wpdb;
        return (bool)$wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM ".BCS_DB::table('messages')." WHERE registration_id=%d AND template_key=%s AND status='sent' LIMIT 1",
            $registration_id, $event
        ));
    }

    public static function save(): void {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        check_admin_referer('bcs_save_notification_settings');
        $input = (array)($_POST['channels'] ?? []);
        $out = [];
        foreach (self::events() as $key => $event) {
            $value = sanitize_key((string)($input[$key] ?? $event['default']));
            $out[$key] = in_array($value, ['off','email','sms','both'], true) ? $value : $event['default'];
        }
        update_option(self::OPTION, $out, false);
        wp_safe_redirect(add_query_arg(['page'=>'bcs-settings','notifications_saved'=>1], admin_url('admin.php')));
        exit;
    }

    public static function render_settings_section(): void {
        if (!current_user_can('manage_options')) return;
        if (sanitize_key(wp_unslash($_GET['page'] ?? '')) !== 'bcs-settings') return;
        $saved = get_option(self::OPTION, []);
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function(){
            const wrap = document.querySelector('.wrap.bcs-admin');
            if (!wrap || document.querySelector('.bcs-notification-settings')) return;
            const section = document.createElement('section');
            section.className = 'bcs-panel bcs-notification-settings';
            section.style.marginTop = '20px';
            section.innerHTML = <?php echo wp_json_encode(self::settings_html(is_array($saved) ? $saved : [])); ?>;
            wrap.appendChild(section);
        });
        </script>
        <?php
    }

    private static function settings_html(array $saved): string {
        $html = '<div class="bcs-panel-head"><div><h2>Powiadomienia workflow</h2><p>Wybierz, na jakim etapie procesu system ma wysyłać e-mail, SMS, oba kanały albo nie wysyłać powiadomienia.</p></div></div>';
        if (isset($_GET['notifications_saved'])) $html .= '<div class="notice notice-success inline"><p>Ustawienia powiadomień zostały zapisane.</p></div>';
        $html .= '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="bcs_save_notification_settings">';
        $html .= wp_nonce_field('bcs_save_notification_settings', '_wpnonce', true, false);
        $html .= '<table class="widefat striped"><thead><tr><th>Etap procesu</th><th>Kiedy jest wysyłane</th><th>Kanał</th></tr></thead><tbody>';
        $options = ['off'=>'Nie wysyłaj','email'=>'E-mail','sms'=>'SMS','both'=>'E-mail i SMS'];
        foreach (self::events() as $key => $event) {
            $current = (string)($saved[$key] ?? $event['default']);
            $html .= '<tr><td><strong>'.esc_html($event['label']).'</strong>'.(!empty($event['once'])?'<br><small>Jednorazowe — system blokuje duplikaty.</small>':'').'</td><td>'.esc_html($event['description']).'</td><td><select name="channels['.esc_attr($key).']">';
            foreach ($options as $value => $label) $html .= '<option value="'.esc_attr($value).'" '.selected($current,$value,false).'>'.esc_html($label).'</option>';
            $html .= '</select></td></tr>';
        }
        $html .= '</tbody></table><p><button class="button button-primary button-hero">Zapisz ustawienia powiadomień</button></p></form>';
        return $html;
    }
}
