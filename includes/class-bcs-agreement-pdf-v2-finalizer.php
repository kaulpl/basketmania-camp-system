<?php
if (!defined('ABSPATH')) exit;

/**
 * Końcowa normalizacja dokumentu V2 przed przekazaniem do Dompdf.
 *
 * W Dompdf 3.x margines BODY jest stosowany jako margines każdej strony.
 * Używamy jawnego marginesu BODY, natomiast stały nagłówek i stopkę ustawiamy
 * dodatnimi współrzędnymi względem fizycznej kartki. Dzięki temu elementy są
 * powtarzane, widoczne i nie nachodzą na obszar treści.
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

        $html = str_replace(
            'position:fixed;top:-25mm;',
            'position:fixed;top:4mm;',
            $html
        );
        $html = str_replace(
            'position:fixed;bottom:-15mm;',
            'position:fixed;bottom:4mm;',
            $html
        );

        $html = preg_replace(
            '~<p\b[^>]*>\s*<strong\b[^>]*>\s*Za(?:ł|l)ącznik\s+nr\s+1\s*[-–]\s*Karta\s+kwalifikacyjna\s+uczestnika\s+wypoczynku\s*</strong>\s*</p>\s*(<section\b[^>]*class="[^"]*bcs-v2-attachment[^"]*")~iu',
            '$1',
            $html,
            1
        ) ?? $html;

        if (class_exists('BCS_Release_071')) {
            $html = BCS_Release_071::normalize_evidence_layout($html);
        }

        return $html;
    }
}
