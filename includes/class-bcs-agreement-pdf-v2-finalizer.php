<?php
if (!defined('ABSPATH')) exit;

/**
 * Końcowa normalizacja dokumentu V2 przed przekazaniem do Dompdf.
 *
 * Dompdf 3.x wymaga średnika w ostatniej deklaracji @page. Bez niego ignorował
 * margines całej strony, przez co elementy fixed wypadały poza arkusz. Usuwamy
 * również techniczne, powtórzone zdanie o Załączniku bezpośrednio przed jego
 * osobną stroną, aby nie tworzyło pustej strony z jednym wierszem.
 */
final class BCS_Agreement_PDF_V2_Finalizer {
    public static function finalize(string $html): string {
        $html = str_replace(
            '@page{margin:32mm 15mm 20mm 15mm}',
            '@page{margin:32mm 15mm 20mm 15mm;}',
            $html
        );

        $html = preg_replace(
            '~<p\b[^>]*>\s*<strong\b[^>]*>\s*Za(?:ł|l)ącznik\s+nr\s+1\s*[-–]\s*Karta\s+kwalifikacyjna\s+uczestnika\s+wypoczynku\s*</strong>\s*</p>\s*(<section\b[^>]*class="[^"]*bcs-v2-attachment[^"]*")~iu',
            '$1',
            $html,
            1
        ) ?? $html;

        return $html;
    }
}
