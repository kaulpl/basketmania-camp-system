<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_061 {
    public static function init(): void {
        // Priorytet 0 jest celowy: skrypt 0.46 rejestruje natywny alert w admin_head
        // z priorytetem 1. Nasz listener musi zostać zarejestrowany jako pierwszy.
        add_action('admin_head', [__CLASS__, 'admin_head_assets'], 0);
    }

    private static function registrations_page(): bool {
        return is_admin()
            && current_user_can('manage_options')
            && sanitize_key((string)($_GET['page'] ?? '')) === 'bcs-registrations';
    }

    public static function admin_head_assets(): void {
        if (!self::registrations_page()) return;

        $config = [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bcs_046'),
            'duration' => 2000,
            'successFallback' => 'Umowa została wysłana do podpisu rodzicowi.',
            'errorFallback' => 'Nie udało się wysłać umowy do podpisu.',
        ];
        $css = BCS_URL.'assets/css/agreement-send-061.css';
        $js = BCS_URL.'assets/js/agreement-send-061.js';
        ?>
        <link rel="stylesheet" id="bcs-agreement-send-061-css" href="<?php echo esc_url($css); ?>?ver=<?php echo esc_attr(BCS_VERSION); ?>">
        <script id="bcs-agreement-send-061-config">
            window.BCSAgreementSend061 = <?php echo wp_json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        </script>
        <script id="bcs-agreement-send-061-js" src="<?php echo esc_url($js); ?>?ver=<?php echo esc_attr(BCS_VERSION); ?>"></script>
        <?php
    }
}
