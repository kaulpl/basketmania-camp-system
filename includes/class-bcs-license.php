<?php

if (!defined('ABSPATH')) exit;

final class BCS_License {
    private const OPTION_KEY = 'bcs_license_key';
    private const STATUS_KEY = 'bcs_license_status';
    private const CACHE_KEY = 'bcs_license_validation';
    private const API_URL = 'https://update.basketmania.pl/api/v1/license';
    private const CACHE_TTL = 12 * HOUR_IN_SECONDS;
    private const GRACE_PERIOD = 3 * DAY_IN_SECONDS;

    public static function init(): void {
        add_action('admin_menu', [self::class, 'register_menu'], 99);
        add_action('admin_init', [self::class, 'handle_form']);
        add_action('admin_notices', [self::class, 'admin_notice']);
    }

    public static function register_menu(): void {
        add_submenu_page(
            'basketmania-camp',
            'Licencja',
            'Licencja',
            'manage_options',
            'bcs-license',
            [self::class, 'render_page']
        );
    }

    public static function get_key(): string {
        return trim((string) get_option(self::OPTION_KEY, ''));
    }

    public static function is_valid(bool $force = false): bool {
        $key = self::get_key();
        if ($key === '') return false;

        $cached = get_option(self::CACHE_KEY, []);
        if (!$force && is_array($cached) && !empty($cached['checked_at'])) {
            $age = time() - (int) $cached['checked_at'];
            if ($age < self::CACHE_TTL) {
                return !empty($cached['valid']);
            }
        }

        $result = self::remote_validate($key);
        if (is_wp_error($result)) {
            if (is_array($cached) && !empty($cached['valid']) && !empty($cached['checked_at'])) {
                return (time() - (int) $cached['checked_at']) < self::GRACE_PERIOD;
            }
            return false;
        }

        update_option(self::CACHE_KEY, [
            'valid' => !empty($result['valid']),
            'checked_at' => time(),
            'expires_at' => isset($result['expires_at']) ? sanitize_text_field((string) $result['expires_at']) : '',
            'message' => isset($result['message']) ? sanitize_text_field((string) $result['message']) : '',
        ], false);
        update_option(self::STATUS_KEY, !empty($result['valid']) ? 'active' : 'inactive', false);

        return !empty($result['valid']);
    }

    public static function get_status(): array {
        $cached = get_option(self::CACHE_KEY, []);
        return is_array($cached) ? $cached : [];
    }

    private static function remote_validate(string $key) {
        $response = wp_remote_post(self::API_URL . '/validate', [
            'timeout' => 15,
            'headers' => ['Accept' => 'application/json'],
            'body' => [
                'license_key' => $key,
                'site_url' => home_url('/'),
                'plugin' => 'basketmania-camp-system',
                'version' => defined('BCS_VERSION') ? BCS_VERSION : '',
            ],
        ]);

        if (is_wp_error($response)) return $response;
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || !is_array($body)) {
            return new WP_Error('bcs_license_api', 'Serwer licencji zwrócił nieprawidłową odpowiedź.');
        }
        return $body;
    }

    public static function handle_form(): void {
        if (!current_user_can('manage_options') || empty($_POST['bcs_license_action'])) return;
        check_admin_referer('bcs_license_save');

        $action = sanitize_key((string) $_POST['bcs_license_action']);
        if ($action === 'deactivate') {
            delete_option(self::OPTION_KEY);
            delete_option(self::CACHE_KEY);
            update_option(self::STATUS_KEY, 'inactive', false);
            wp_safe_redirect(add_query_arg(['page' => 'bcs-license', 'bcs_license' => 'deactivated'], admin_url('admin.php')));
            exit;
        }

        $key = isset($_POST['bcs_license_key']) ? sanitize_text_field(wp_unslash($_POST['bcs_license_key'])) : '';
        update_option(self::OPTION_KEY, $key, false);
        delete_option(self::CACHE_KEY);
        $valid = self::is_valid(true);
        wp_safe_redirect(add_query_arg([
            'page' => 'bcs-license',
            'bcs_license' => $valid ? 'activated' : 'invalid',
        ], admin_url('admin.php')));
        exit;
    }

    public static function admin_notice(): void {
        if (!current_user_can('manage_options') || self::is_valid()) return;
        $url = admin_url('admin.php?page=bcs-license');
        echo '<div class="notice notice-error"><p><strong>Basketmania Camp System jest nieaktywna.</strong> Wprowadź ważny kod licencji, aby uruchomić moduły systemu. <a href="' . esc_url($url) . '">Przejdź do licencji</a>.</p></div>';
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) return;
        $status = self::get_status();
        $valid = self::is_valid();
        ?>
        <div class="wrap">
            <h1>Licencja Basketmania Camp System</h1>
            <p>Status: <strong><?php echo $valid ? 'Aktywna' : 'Nieaktywna'; ?></strong></p>
            <?php if (!empty($status['expires_at'])): ?>
                <p>Ważna do: <strong><?php echo esc_html($status['expires_at']); ?></strong></p>
            <?php endif; ?>
            <?php if (!empty($status['message'])): ?>
                <p><?php echo esc_html($status['message']); ?></p>
            <?php endif; ?>
            <form method="post">
                <?php wp_nonce_field('bcs_license_save'); ?>
                <table class="form-table"><tr>
                    <th><label for="bcs_license_key">Kod licencji</label></th>
                    <td><input name="bcs_license_key" id="bcs_license_key" type="text" class="regular-text" value="<?php echo esc_attr(self::get_key()); ?>" autocomplete="off"></td>
                </tr></table>
                <p class="submit">
                    <button class="button button-primary" name="bcs_license_action" value="activate">Aktywuj i sprawdź</button>
                    <?php if (self::get_key() !== ''): ?>
                        <button class="button" name="bcs_license_action" value="deactivate">Usuń licencję</button>
                    <?php endif; ?>
                </p>
            </form>
        </div>
        <?php
    }
}
