<?php
if (!defined('ABSPATH')) exit;

/**
 * 1.01 – hotfix widoku Mailingu: pojedynczy renderer strony i pełna szerokość UI.
 */
final class BCS_Release_101 {
    private const PAGE = 'bcs-mailing';
    private const PARENT = 'bcs-dashboard';

    public static function init(): void {
        // add_submenu_page() dodaje callback strony do hooka ekranu. Samo
        // remove_submenu_page() usuwa pozycję menu, ale nie usuwa tego callbacku.
        // 1.00 rejestrowało więc nowy renderer obok starego renderera 0.96.
        add_action('admin_menu', [__CLASS__, 'detach_legacy_mailing_renderer'], 1500);
    }

    public static function detach_legacy_mailing_renderer(): void {
        if (!function_exists('get_plugin_page_hookname')) return;
        $hook = get_plugin_page_hookname(self::PAGE, self::PARENT);
        if ($hook === '') return;

        remove_action($hook, [BCS_Release_096::class, 'mailing_page']);
    }
}
