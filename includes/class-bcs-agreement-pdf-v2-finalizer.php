<?php
if (!defined('ABSPATH')) exit;

/**
 * Końcowa normalizacja dokumentu V2 przed przekazaniem do Dompdf.
 *
 * W Dompdf 3.x margines BODY jest stosowany jako margines każdej strony.
 * Używamy więc jawnego marginesu BODY, zamiast polegać na kolidujących
 * interpretacjach @page. Stały nagłówek i stopka są pozycjonowane względem
 * tego bezpiecznego obszaru i nie są nakładane po renderowaniu.
 */
final class BCS_Agreement_PDF_V2_Finalizer {
    public static function finalize(string $html): string {
        $html = str_replace(
            '@page{margin:32mm 15mm 20mm 15mm}',
            '@page{margin:0;}',
            $html
        );

        $html = str_replace(
            'html,body{margin:0;padding:0;background:#fff;color:#172033;font-family:"DejaVu Sans",Arial,sans-serif;font-size:10pt;line-height:1.38}',
            'html{margin:0;padding:0;background:#fff}body{margin:32mm 15mm 20mm 15mm;padding:0;background:#fff;color:#172033;font-family:"DejaVu Sans",Arial,sans-serif;font-size:10pt;line-height:1.38}',
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
