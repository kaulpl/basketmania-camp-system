<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_0254 {
    public static function init(): void {
        self::migrate_agreement_template();
    }

    private static function migrate_agreement_template(): void {
        if (get_option('bcs_release_0254_agreement_template_migrated')) return;

        $saved = get_option('bcs_content_templates', []);
        if (!is_array($saved)) $saved = [];
        $current = (string)($saved['documents']['agreement'] ?? '');
        $is_legacy_default = $current === ''
            || str_contains($current, 'Szczegółowe zasady rezygnacji, bezpieczeństwa i organizacji')
            || str_contains($current, '<h1>Umowa uczestnictwa nr {{AGREEMENT_NUMBER}}</h1>');

        if ($is_legacy_default) {
            $path = BCS_DIR . 'templates/agreement-default.html';
            if (is_readable($path)) {
                $template = file_get_contents($path);
                if (is_string($template) && trim($template) !== '') {
                    $saved['documents']['agreement'] = $template;
                    update_option('bcs_content_templates', $saved, false);
                }
            }
        }

        update_option('bcs_release_0254_agreement_template_migrated', 1, false);
    }
}
