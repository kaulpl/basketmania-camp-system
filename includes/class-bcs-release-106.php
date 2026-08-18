<?php
if (!defined('ABSPATH')) exit;

/**
 * 1.06 – czytelny podział Załącznika nr 1 (karty kwalifikacyjnej).
 *
 * Sekcje I–II są oznaczone jako wypełniane przez rodziców/opiekunów,
 * sekcje III–VI jako wypełniane przez organizatora wypoczynku. Pod sekcją VI
 * pozostają papierowe pola na datę i podpis organizatora wypoczynku.
 *
 * Migracja dotyczy wyłącznie edytowalnego wzoru umowy na przyszłość. Nie
 * modyfikuje historycznych, podpisanych wersji dokumentów.
 */
final class BCS_Release_106 {
    private const MIGRATION_OPTION = 'bcs_release_106_attachment_sections_migrated';
    private const PARENT_LABEL = 'Wypełniają rodzice/opiekunowie';
    private const ORGANIZER_LABEL = 'Wypełnia organizator wypoczynku';
    private const SIGNATURE_LABEL = 'Podpis organizatora wypoczynku:';

    public static function init(): void {
        self::migrate_agreement_template();
    }

    private static function parent_block(): string {
        return '<table class="bcs-attachment-responsibility bcs-attachment-responsibility-parent"><tr><th>'
            .self::PARENT_LABEL
            .'</th></tr></table>';
    }

    private static function organizer_block(): string {
        return '<table class="bcs-attachment-responsibility bcs-attachment-responsibility-organizer"><tr><th>'
            .self::ORGANIZER_LABEL
            .'</th></tr></table>';
    }

    private static function organizer_signature_block(): string {
        return '<table class="bcs-attachment-organizer-signature"><tr>'
            .'<td><strong>Data:</strong><br>................................................................</td>'
            .'<td><strong>'.self::SIGNATURE_LABEL.'</strong><br>................................................................</td>'
            .'</tr></table>';
    }

    /**
     * Dodaje oznaczenia do istniejącego pełnego wzoru bez przebudowy jego treści.
     * Funkcja jest idempotentna, dzięki czemu ponowne uruchomienie nie dubluje pól.
     */
    public static function transform_agreement_template(string $html): string {
        if (trim($html) === '') return $html;

        $plain = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = mb_strtoupper((string)preg_replace('/\s+/u', ' ', trim($plain)), 'UTF-8');
        if (!str_contains($plain, 'ZAŁĄCZNIK NR 1')
            || !str_contains($plain, 'I. INFORMACJE DOTYCZĄCE WYPOCZYNKU')
            || !str_contains($plain, 'III. DECYZJA ORGANIZATORA')
            || !str_contains($plain, 'VI. INFORMACJA I SPOSTRZEŻENIA WYCHOWAWCY')) {
            return $html;
        }

        if (!str_contains($html, self::PARENT_LABEL)) {
            $html = (string)preg_replace(
                '~(?=<h3\b[^>]*>\s*I\.\s*INFORMACJE\s+DOTYCZĄCE\s+WYPOCZYNKU\s*</h3>)~iu',
                self::parent_block(),
                $html,
                1
            );
        }

        if (!str_contains($html, self::ORGANIZER_LABEL)) {
            $html = (string)preg_replace(
                '~(?=<h3\b[^>]*>\s*III\.\s*DECYZJA\s+ORGANIZATORA\b.*?</h3>)~isu',
                self::organizer_block(),
                $html,
                1
            );
        }

        if (!str_contains($html, self::SIGNATURE_LABEL)) {
            $pattern = '~(<h3\b[^>]*>\s*VI\.\s*INFORMACJA\s+I\s+SPOSTRZEŻENIA\s+WYCHOWAWCY\s*</h3>\s*<p\b[^>]*>.*?</p>)~isu';
            $html = (string)preg_replace_callback(
                $pattern,
                static fn(array $m): string => $m[1].self::organizer_signature_block(),
                $html,
                1
            );
        }

        return $html;
    }

    /**
     * Aktualizuje wzór zapisany w module Szablony. Jeżeli instalacja nie ma
     * własnego wzoru, punktem wyjścia jest pełny templates/agreement-default.html.
     */
    public static function migrate_agreement_template(): void {
        if (get_option(self::MIGRATION_OPTION)) return;

        $saved = get_option('bcs_content_templates', []);
        if (!is_array($saved)) $saved = [];
        $current = (string)($saved['documents']['agreement'] ?? '');

        if (trim($current) === '') {
            $path = BCS_DIR.'templates/agreement-default.html';
            if (is_readable($path)) {
                $fallback = file_get_contents($path);
                if (is_string($fallback)) $current = $fallback;
            }
        }

        $updated = self::transform_agreement_template($current);
        if ($updated !== '' && $updated !== (string)($saved['documents']['agreement'] ?? '')) {
            if (!isset($saved['documents']) || !is_array($saved['documents'])) $saved['documents'] = [];
            $saved['documents']['agreement'] = $updated;
            update_option('bcs_content_templates', $saved, false);
        }

        update_option(self::MIGRATION_OPTION, 1, false);
    }
}
