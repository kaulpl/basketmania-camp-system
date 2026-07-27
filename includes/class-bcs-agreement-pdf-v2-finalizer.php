<?php
if (!defined('ABSPATH')) exit;

/**
 * Końcowa normalizacja dokumentu V2 przed przekazaniem do Dompdf.
 *
 * Dompdf traktuje `margin` elementu BODY jak margines strony. Ustawienie
 * `body{margin:0}` kasowało więc wartości z @page i wypychało stały nagłówek
 * oraz stopkę poza arkusz. Finalizer pozostawia margines wyłącznie w @page,
 * dodaje jednoznaczny średnik deklaracji i usuwa redundantny wiersz przed
 * osobną stroną Załącznika nr 1.
 */
final class BCS_Agreement_PDF_V2_Finalizer {
    public static function finalize(string $html): string {
        $html = str_replace(
            '@page{margin:32mm 15mm 20mm 15mm}',
            '@page{margin:32mm 15mm 20mm 15mm;}',
            $html
        );

        $html = str_replace(
            'html,body{margin:0;padding:0;background:#fff;color:#172033;font-family:"DejaVu Sans",Arial,sans-serif;font-size:10pt;line-height:1.38}',
            'html{margin:0;padding:0;background:#fff}body{padding:0;background:#fff;color:#172033;font-family:"DejaVu Sans",Arial,sans-serif;font-size:10pt;line-height:1.38}',
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
