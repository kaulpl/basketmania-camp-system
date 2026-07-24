<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_028 {
    public static function init(): void {
        self::migrate_agreement_template();
    }

    private static function migrate_agreement_template(): void {
        if (get_option('bcs_release_028_agreement_template_migrated')) return;

        $saved = get_option('bcs_content_templates', []);
        if (!is_array($saved)) $saved = [];
        $current = (string)($saved['documents']['agreement'] ?? '');

        if ($current !== '') {
            $current = preg_replace(
                '~<table\s+class="signatures"[^>]*>.*?</table>~is',
                '',
                $current
            );
            $current = preg_replace(
                '~<div\s+class="invoice-section"[^>]*>.*?</div>\s*</div>~is',
                '',
                $current
            );
            $current = str_replace(
                '{{INVOICE_BUYER_NAME}}{{INVOICE_STREET}}{{INVOICE_POSTAL_CODE}}{{INVOICE_CITY}}{{INVOICE_NIP}}{{INVOICE_NOTES}}',
                '',
                $current
            );
            $saved['documents']['agreement'] = $current;
            update_option('bcs_content_templates', $saved, false);
        }

        update_option('bcs_release_028_agreement_template_migrated', 1, false);
    }
}
