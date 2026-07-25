<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_056 {
    private const MIGRATION_OPTION = 'bcs_templates_migrated_056_paid_button';

    public static function init(): void {
        self::migrate_paid_template();
    }

    public static function button_html(): string {
        return '<a href="{{PORTAL_URL}}" style="display:inline-block;background:#f97316;color:#ffffff;padding:13px 20px;border-radius:8px;text-decoration:none;font-weight:700">Otwórz Panel Rodzica</a>';
    }

    public static function paid_template(): array {
        return [
            'name' => 'Potwierdzenie opłacenia',
            'subject' => 'Udział w {{CAMP_NAME}} został opłacony',
            'body' => 'Dzień dobry {{PARENT_NAME}},<br><br>potwierdzamy opłacenie udziału <strong>{{CHILD_NAME}}</strong> w turnusie <strong>{{CAMP_NAME}}</strong>.<br><br>Pakiet dokumentów został dołączony do wiadomości i jest również dostępny w Panelu Rodzica.<br><br>'.self::button_html(),
            'sms' => '',
        ];
    }

    private static function normalize_paid_template(array $template): array {
        $default = self::paid_template();
        $body = trim((string)($template['body'] ?? ''));

        $has_portal_button = (bool)preg_match(
            '/<a\b[^>]*href=["\']\{\{PORTAL_URL\}\}["\'][^>]*>/i',
            $body
        );
        $legacy_plain_link = str_contains($body, '{{PORTAL_URL}}') && !$has_portal_button;

        if ($body === '' || $legacy_plain_link) {
            $template['body'] = $default['body'];
        }

        foreach (['name','subject','sms'] as $field) {
            if (!array_key_exists($field, $template) || $template[$field] === '') {
                $template[$field] = $default[$field];
            }
        }

        return $template;
    }

    private static function migrate_paid_template(): void {
        if (get_option(self::MIGRATION_OPTION)) return;

        $content_templates = get_option('bcs_content_templates', []);
        if (!is_array($content_templates)) $content_templates = [];
        $before = (array)($content_templates['emails']['paid'] ?? []);
        $content_templates['emails']['paid'] = self::normalize_paid_template($before);
        update_option('bcs_content_templates', $content_templates, false);

        // Zgodność ze starszym magazynem szablonów używanym przed centralnym modułem Szablony.
        $legacy_templates = get_option('bcs_message_templates', []);
        if (is_array($legacy_templates) && isset($legacy_templates['paid'])) {
            $legacy_templates['paid'] = self::normalize_paid_template((array)$legacy_templates['paid']);
            update_option('bcs_message_templates', $legacy_templates, false);
        }

        update_option(self::MIGRATION_OPTION, 1, false);
    }
}
