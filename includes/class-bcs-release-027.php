<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_027 {
    public static function init(): void {
        self::migrate_agreement_template();
    }

    private static function migrate_agreement_template(): void {
        if (get_option('bcs_release_027_agreement_template_migrated')) return;

        $saved = get_option('bcs_content_templates', []);
        if (!is_array($saved)) $saved = [];
        $current = (string)($saved['documents']['agreement'] ?? '');

        $is_previous_default = $current === ''
            || str_contains($current, 'agreement-default.html')
            || str_contains($current, 'DANE KONTAKTOWE I INFORMACJE O OBOZIE')
            || str_contains($current, '§8 ZASADY PORZĄDKOWE, TELEFONY I ODPOWIEDZIALNOŚĆ UCZESTNIKA');

        if ($is_previous_default) {
            $path = BCS_DIR . 'templates/agreement-v027.html';
            if (is_readable($path)) {
                $template = file_get_contents($path);
                if (is_string($template) && trim($template) !== '') {
                    $saved['documents']['agreement'] = $template;
                    update_option('bcs_content_templates', $saved, false);
                }
            }
        }

        update_option('bcs_release_027_agreement_template_migrated', 1, false);
    }
}
