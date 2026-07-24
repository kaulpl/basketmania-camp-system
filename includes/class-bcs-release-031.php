<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_031 {
    public static function init(): void {
        self::migrate_agreement_template();
    }

    private static function migrate_agreement_template(): void {
        if (get_option('bcs_release_031_template_migrated')) return;

        $saved = get_option('bcs_content_templates', []);
        if (!is_array($saved)) $saved = [];
        $template = (string)($saved['documents']['agreement'] ?? '');

        if ($template !== '') {
            $original = $template;

            // Korekta wersji 0.30: w danych Organizatora ma być telefon,
            // a nie numer umowy. Sam numer umowy może pozostać w swoim
            // odrębnym miejscu dokumentu.
            $template = preg_replace(
                '~\s*<strong>Numer umowy:</strong>\s*\{\{AGREEMENT_NUMBER\}\}\s*,?~u',
                ' <strong>Telefon:</strong> {{ORGANIZER_PHONE}},',
                $template,
                1
            );

            // Dla szablonów, w których migracja 0.30 nie zadziałała,
            // dodajemy telefon bezpośrednio po adresie siedziby.
            if (!str_contains($template, '<strong>Telefon:</strong> {{ORGANIZER_PHONE}}')) {
                $patterns = [
                    '~(z siedzibą w\s*\{\{ORGANIZER_ADDRESS\}\},?)~u',
                    '~(z siedzibą:\s*\{\{ORGANIZER_ADDRESS\}\},?)~u',
                ];
                foreach ($patterns as $pattern) {
                    $updated = preg_replace($pattern, '$1 <strong>Telefon:</strong> {{ORGANIZER_PHONE}},', $template, 1, $count);
                    if ($count > 0) {
                        $template = $updated;
                        break;
                    }
                }
            }

            if ($template !== $original) {
                $saved['documents']['agreement'] = $template;
                update_option('bcs_content_templates', $saved, false);
            }
        }

        update_option('bcs_release_031_template_migrated', 1, false);
    }
}
