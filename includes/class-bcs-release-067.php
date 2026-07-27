<?php
if (!defined('ABSPATH')) exit;

/**
 * Wersja 0.67:
 * - nagłówek i stopka PDF są rysowane przez Canvas::page_script na każdej stronie,
 * - elementy HTML nagłówka i stopki są ukryte w wydruku, aby nie trafiały do treści pierwszej strony,
 * - logo jest wyśrodkowane,
 * - przywrócone są pomarańczowe akcenty sekcji oraz pogrubień.
 */
final class BCS_Release_067 {
    private const STYLE_ID = 'bcs-agreement-style-067';
    private const ORANGE = '#c2410c';
    private const ORANGE_LIGHT = '#fed7aa';
    private const NAVY = '#172033';

    public static function init(): void {
        self::replace_preview_handlers();
    }

    /**
     * Podglądy HTML nie przechodzą przez BCS_PDF, dlatego dokładamy warstwę 0.67
     * po rendererze 0.66 również w podglądzie administratora i rodzica.
     */
    private static function replace_preview_handlers(): void {
        if (class_exists('BCS_Release_066')) {
            remove_action('admin_post_bcs_agreement_view', ['BCS_Release_066', 'render_agreement_view'], 0);
            remove_action('admin_post_nopriv_bcs_agreement_view', ['BCS_Release_066', 'render_agreement_view'], 0);
            add_action('admin_post_bcs_agreement_view', [__CLASS__, 'render_agreement_view'], 0);
            add_action('admin_post_nopriv_bcs_agreement_view', [__CLASS__, 'render_agreement_view'], 0);

            remove_action('admin_post_bcs_agreement_version_preview_054', ['BCS_Release_066', 'render_version_preview']);
            add_action('admin_post_bcs_agreement_version_preview_054', [__CLASS__, 'render_version_preview']);
        }
    }

    public static function render_agreement_view(): void {
        ob_start([__CLASS__, 'buffer_agreement_html']);
        BCS_Release_066::render_agreement_view();
    }

    public static function render_version_preview(): void {
        ob_start([__CLASS__, 'buffer_agreement_html']);
        BCS_Release_066::render_version_preview();
    }

    public static function buffer_agreement_html(string $html): string {
        return self::prepare_agreement_html($html);
    }

    public static function is_agreement_document(string $html, string $title = ''): bool {
        return stripos($title, 'umowa') !== false
            || str_contains($html, 'bcs-document-052')
            || str_contains($html, 'bcs-document-066')
            || str_contains($html, 'bcs-agreement')
            || stripos($html, 'UMOWA UDZIAŁU') !== false;
    }

    public static function agreement_css(): string {
        return '
            @page{margin:29mm 14mm 24mm 14mm;background:#fff}
            html,body{margin:0!important;padding:0!important;background:#fff!important;background-color:#fff!important;color:#172033!important}
            .bcs-document-052,.bcs-document-066,.bcs-document-067{font-family:"DejaVu Sans",Arial,sans-serif!important;font-size:10pt!important;line-height:1.38!important;background:#fff!important;background-color:#fff!important;color:#172033!important;border:0!important;box-shadow:none!important}

            /* PDF: nagłówek i stopkę rysuje Canvas::page_script. Elementy HTML nie mogą wejść do przepływu pierwszej strony. */
            .bcs-document-header,.bcs-document-footer{display:none!important}

            .bcs-document-content{display:block!important;position:relative!important;background:#fff!important;background-color:#fff!important;color:#172033!important;box-shadow:none!important}
            .bcs-document-content *{background-color:#fff!important;background-image:none!important;box-shadow:none!important}
            .bcs-document-content h1{font-size:16pt!important;line-height:1.2!important;text-align:center!important;margin:0 0 10px!important;color:#172033!important}
            .bcs-document-content h2{font-size:11.5pt!important;line-height:1.25!important;margin:12px 0 6px!important;padding:0 0 2px!important;color:#c2410c!important;border-bottom:1px solid #fed7aa!important;page-break-after:avoid!important}
            .bcs-document-content h3{font-size:10.5pt!important;line-height:1.25!important;margin:9px 0 4px!important;color:#ea580c!important;page-break-after:avoid!important}
            .bcs-document-content strong,.bcs-document-content b{color:#c2410c!important;font-weight:700!important}
            .bcs-document-content p{margin:0 0 5px!important;color:#172033!important}
            .bcs-document-content ol,.bcs-document-content ul{margin:4px 0 7px 18px!important;padding:0!important;color:#172033!important}
            .bcs-document-content li{margin:0 0 3px!important;color:#172033!important}
            .bcs-document-content table{width:100%!important;border-collapse:collapse!important;margin:6px 0 9px!important;font-size:9.2pt!important;page-break-inside:auto!important;background:#fff!important}
            .bcs-document-content tr{page-break-inside:avoid!important;background:#fff!important}
            .bcs-document-content td,.bcs-document-content th{border:1px solid #f0a36f!important;padding:4px 5px!important;vertical-align:top!important;background:#fff!important;color:#172033!important}
            .bcs-document-content th{font-weight:700!important;color:#c2410c!important}
            .proof,.bcs-proof-page,.bcs-proof-start-057{page-break-before:always!important;break-before:page!important;border:0!important;border-left:3px solid #f97316!important;padding:0 0 0 10px!important;margin:0!important;box-sizing:border-box!important;background:#fff!important;background-color:#fff!important;box-shadow:none!important}
            .proof h2,.bcs-proof-page h2,.bcs-proof-start-057 h2{color:#c2410c!important;margin:0 0 10px!important}
            .bcs-page-break-before{page-break-before:always!important;break-before:page!important}
            .bcs-keep-with-next{page-break-after:avoid!important;break-after:avoid-page!important}

            @media screen{
                html,body{background:#fff!important;padding:0!important}
                .bcs-document-052,.bcs-document-066,.bcs-document-067{max-width:820px!important;margin:0 auto!important;display:flex!important;flex-direction:column!important;background:#fff!important;border:0!important;box-shadow:none!important}
                .bcs-document-header{display:block!important;position:static!important;order:1!important;height:auto!important;min-height:70px!important;padding:13px 34px 10px!important;text-align:center!important;background:#fff!important;border-bottom:2px solid #f97316!important}
                .bcs-document-header img{display:block!important;height:54px!important;width:auto!important;max-width:260px!important;margin:0 auto!important;object-fit:contain!important}
                .bcs-document-content{order:2!important;padding:30px 38px 34px!important;background:#fff!important}
                .bcs-document-footer{display:block!important;position:static!important;order:3!important;height:auto!important;min-height:0!important;padding:10px 28px 14px!important;text-align:center!important;background:#172033!important;background-color:#172033!important;color:#fff!important}
                .bcs-document-footer-rule{display:block!important;height:1px!important;margin:0 0 8px!important;background:#fff!important;background-color:#fff!important}
                .bcs-document-footer-text{display:block!important;background:transparent!important;color:#fff!important;font-size:8pt!important;line-height:1.35!important}
                .bcs-page-break-before,.proof,.bcs-proof-page,.bcs-proof-start-057{page-break-before:auto!important;break-before:auto!important;margin-top:30px!important}
            }
        ';
    }

    public static function prepare_agreement_html(string $html): string {
        if (trim($html) === '' || !self::is_agreement_document($html)) return $html;

        if (!class_exists('DOMDocument')) {
            return '<style id="'.self::STYLE_ID.'">'.self::agreement_css().'</style>'.$html;
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $ok = $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$ok) return '<style id="'.self::STYLE_ID.'">'.self::agreement_css().'</style>'.$html;

        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('//*[@id="'.self::STYLE_ID.'"]') as $oldStyle) {
            if ($oldStyle->parentNode) $oldStyle->parentNode->removeChild($oldStyle);
        }

        $document = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' bcs-document-052 ')]")->item(0);
        if ($document instanceof DOMElement) {
            $classes = preg_split('/\s+/', trim($document->getAttribute('class'))) ?: [];
            if (!in_array('bcs-document-067', $classes, true)) $classes[] = 'bcs-document-067';
            $document->setAttribute('class', trim(implode(' ', array_filter($classes))));
            $document->setAttribute('data-bcs-pdf-decoration', 'canvas-page-script-067');
        }

        $style = $dom->createElement('style');
        $style->setAttribute('id', self::STYLE_ID);
        $style->appendChild($dom->createTextNode(self::agreement_css()));
        $head = $dom->getElementsByTagName('head')->item(0);
        if ($head) $head->appendChild($style);
        elseif ($document && $document->parentNode) $document->parentNode->insertBefore($style, $document);

        $result = (string)$dom->saveHTML();
        return preg_replace('/^<\?xml[^>]*>\s*/i', '', $result) ?? $result;
    }

    public static function footer_text_from_html(string $html): string {
        if (!class_exists('DOMDocument')) return '';
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $ok = $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$ok) return '';

        $xpath = new DOMXPath($dom);
        $node = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' bcs-document-footer-text ')]")->item(0);
        if (!$node) $node = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' bcs-document-footer ')]")->item(0);
        if (!$node) return '';
        return trim((string)preg_replace('/\s+/u', ' ', html_entity_decode($node->textContent ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private static function logo_temp_path(): string {
        if (!class_exists('BCS_Release_066')) return '';
        $uri = BCS_Release_066::logo_data_uri();
        if (!preg_match('~^data:image/(png|jpeg|gif|webp);base64,(.+)$~s', $uri, $match)) return '';
        $bytes = base64_decode(preg_replace('/\s+/', '', $match[2]), true);
        if (!is_string($bytes) || $bytes === '') return '';

        $ext = $match[1] === 'jpeg' ? 'jpg' : $match[1];
        $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        $path = $dir.DIRECTORY_SEPARATOR.'bcs-agreement-logo-'.md5($bytes).'.'.$ext;
        if (!is_readable($path)) {
            $written = @file_put_contents($path, $bytes, LOCK_EX);
            if ($written === false) return '';
        }
        return $path;
    }

    private static function text_width(object $fontMetrics, string $text, mixed $font, float $size): float {
        if (method_exists($fontMetrics, 'getTextWidth')) {
            return (float)$fontMetrics->getTextWidth($text, $font, $size);
        }
        return max(1.0, mb_strlen($text, 'UTF-8') * $size * 0.52);
    }

    private static function wrap_text(string $text, object $fontMetrics, mixed $font, float $size, float $maxWidth): array {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            if ($word === '') continue;
            $candidate = $line === '' ? $word : $line.' '.$word;
            if ($line !== '' && self::text_width($fontMetrics, $candidate, $font, $size) > $maxWidth) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }
        if ($line !== '') $lines[] = $line;
        if (!$lines) $lines[] = 'Basketmania Camp';
        return array_slice($lines, 0, 3);
    }

    /**
     * Dompdf oficjalnie zaleca Canvas::page_script do elementów rysowanych na każdej
     * stronie. Callback jest wykonywany osobno dla każdej wyrenderowanej strony.
     */
    public static function apply_canvas_header_footer(object $pdf, string $html, string $title = ''): void {
        if (!self::is_agreement_document($html, $title)
            || !method_exists($pdf, 'getCanvas')
            || !method_exists($pdf, 'getFontMetrics')) {
            return;
        }

        $canvas = $pdf->getCanvas();
        if (!is_object($canvas) || !method_exists($canvas, 'page_script')) return;

        $footerText = self::footer_text_from_html($html);
        if ($footerText === '') $footerText = 'Basketmania Camp';
        $logoPath = self::logo_temp_path();

        $navy = [23 / 255, 32 / 255, 51 / 255];
        $orange = [249 / 255, 115 / 255, 22 / 255];
        $white = [1, 1, 1];

        $canvas->page_script(static function ($pageNumber, $pageCount, $pageCanvas, $fontMetrics) use ($footerText, $logoPath, $navy, $orange, $white): void {
            $width = (float)$pageCanvas->get_width();
            $height = (float)$pageCanvas->get_height();

            // Nagłówek: wyśrodkowane logo i pomarańczowa linia zamykająca nagłówek.
            $logoWidth = 112.0;
            $logoHeight = 47.8;
            $logoX = max(0.0, ($width - $logoWidth) / 2);
            if ($logoPath !== '' && method_exists($pageCanvas, 'image')) {
                try {
                    $pageCanvas->image($logoPath, $logoX, 7.0, $logoWidth, $logoHeight);
                } catch (Throwable $e) {
                    // Brak obrazu nie może zablokować wygenerowania dokumentu.
                }
            }
            if (method_exists($pageCanvas, 'line')) {
                $pageCanvas->line(39.5, 62.0, $width - 39.5, 62.0, $orange, 1.1);
            }

            // Stopka: granatowe pole, biała linia i dane Organizatora.
            $footerHeight = 50.0;
            $footerY = $height - $footerHeight;
            if (method_exists($pageCanvas, 'filled_rectangle')) {
                $pageCanvas->filled_rectangle(0.0, $footerY, $width, $footerHeight, $navy);
            }
            if (method_exists($pageCanvas, 'line')) {
                $pageCanvas->line(28.0, $footerY + 8.0, $width - 28.0, $footerY + 8.0, $white, 0.75);
            }

            $font = method_exists($fontMetrics, 'getFont') ? $fontMetrics->getFont('DejaVu Sans', 'normal') : null;
            $size = 6.7;
            $lines = self::wrap_text($footerText, $fontMetrics, $font, $size, $width - 58.0);
            $lineHeight = 8.3;
            $availableHeight = $footerHeight - 15.0;
            $textHeight = count($lines) * $lineHeight;
            $y = $footerY + 14.0 + max(0.0, ($availableHeight - $textHeight) / 2);

            foreach ($lines as $line) {
                $textWidth = self::text_width($fontMetrics, $line, $font, $size);
                $x = max(20.0, ($width - $textWidth) / 2);
                if (method_exists($pageCanvas, 'text')) {
                    $pageCanvas->text($x, $y, $line, $font, $size, $white);
                }
                $y += $lineHeight;
            }
        });
    }
}
