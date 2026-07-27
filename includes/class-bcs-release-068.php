<?php
if (!defined('ABSPATH')) exit;

/**
 * Wersja 0.68:
 * - jedna kanoniczna rezerwa marginesów dla nagłówka i stopki Canvas,
 * - biała stopka z pomarańczowym tekstem i linią,
 * - Załącznik nr 1 oraz dowody SMS rozpoczynają osobne strony,
 * - karta kwalifikacyjna ma zwarty skład mieszczący ją na jednej stronie A4.
 */
final class BCS_Release_068 {
    private const STYLE_ID = 'bcs-agreement-style-068';

    public static function init(): void {
        self::replace_preview_handlers();
    }

    private static function replace_preview_handlers(): void {
        if (!class_exists('BCS_Release_067')) return;
        remove_action('admin_post_bcs_agreement_view', ['BCS_Release_067', 'render_agreement_view'], 0);
        remove_action('admin_post_nopriv_bcs_agreement_view', ['BCS_Release_067', 'render_agreement_view'], 0);
        add_action('admin_post_bcs_agreement_view', [__CLASS__, 'render_agreement_view'], 0);
        add_action('admin_post_nopriv_bcs_agreement_view', [__CLASS__, 'render_agreement_view'], 0);
        remove_action('admin_post_bcs_agreement_version_preview_054', ['BCS_Release_067', 'render_version_preview']);
        add_action('admin_post_bcs_agreement_version_preview_054', [__CLASS__, 'render_version_preview']);
    }

    public static function render_agreement_view(): void {
        ob_start([__CLASS__, 'buffer_agreement_html']);
        BCS_Release_067::render_agreement_view();
    }

    public static function render_version_preview(): void {
        ob_start([__CLASS__, 'buffer_agreement_html']);
        BCS_Release_067::render_version_preview();
    }

    public static function buffer_agreement_html(string $html): string {
        return self::prepare_agreement_html($html);
    }

    private static function is_agreement(string $html, string $title = ''): bool {
        return class_exists('BCS_Release_067')
            ? BCS_Release_067::is_agreement_document($html, $title)
            : stripos($title, 'umowa') !== false || stripos($html, 'UMOWA UDZIAŁU') !== false;
    }

    private static function normalized(string $text): string {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return mb_strtoupper((string)preg_replace('/\s+/u', ' ', trim($text)), 'UTF-8');
    }

    private static function has_class(DOMElement $element, string $class): bool {
        $classes = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [];
        return in_array($class, $classes, true);
    }

    private static function add_class(DOMElement $element, string $class): void {
        $classes = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [];
        if (!in_array($class, $classes, true)) $classes[] = $class;
        $element->setAttribute('class', trim(implode(' ', array_filter($classes))));
    }

    private static function is_attachment_heading(DOMElement $node): bool {
        $text = self::normalized($node->textContent ?? '');
        return (str_contains($text, 'ZAŁĄCZNIK NR 1') || str_contains($text, 'ZALACZNIK NR 1'))
            && str_contains($text, 'KARTA KWALIFIKACYJNA UCZESTNIKA WYPOCZYNKU');
    }

    private static function is_proof_node(DOMElement $node): bool {
        if (self::has_class($node, 'proof') || self::has_class($node, 'bcs-proof-page')
            || self::has_class($node, 'bcs-proof-start-057')) return true;
        $text = self::normalized($node->textContent ?? '');
        return str_contains($text, 'SEKCJA DOWODOWA ZAWARCIA UMOWY')
            || str_contains($text, 'POTWIERDZENIE ZAWARCIA UMOWY');
    }

    private static function direct_child_under(DOMNode $node, DOMNode $ancestor): ?DOMNode {
        $current = $node;
        while ($current->parentNode && $current->parentNode !== $ancestor) $current = $current->parentNode;
        return $current->parentNode === $ancestor ? $current : null;
    }

    private static function remove_previous_page_rules(DOMDocument $dom): void {
        foreach ($dom->getElementsByTagName('style') as $style) {
            if (!$style instanceof DOMElement || $style->getAttribute('id') === self::STYLE_ID) continue;
            $css = (string)$style->textContent;
            $clean = preg_replace('~@page\s*(?:[^\{]*)\{[^\{\}]*\}~is', '', $css);
            if (!is_string($clean) || $clean === $css) continue;
            while ($style->firstChild) $style->removeChild($style->firstChild);
            $style->appendChild($dom->createTextNode($clean));
        }
    }

    private static function mark_attachment_and_proof(DOMDocument $dom, DOMXPath $xpath): void {
        $attachmentHeading = null;
        foreach ($xpath->query('//h1|//h2|//h3') as $heading) {
            if ($heading instanceof DOMElement && self::is_attachment_heading($heading)) {
                $attachmentHeading = $heading;
                break;
            }
        }

        $proof = null;
        foreach ($xpath->query('//*') as $node) {
            if ($node instanceof DOMElement && self::is_proof_node($node)) {
                $proof = $node;
                if (self::has_class($node, 'proof') || self::has_class($node, 'bcs-proof-start-057')) break;
            }
        }
        if ($proof instanceof DOMElement) self::add_class($proof, 'bcs-proof-page-068');
        if (!$attachmentHeading instanceof DOMElement) return;

        $parent = $attachmentHeading->parentNode;
        if (!$parent instanceof DOMNode) {
            self::add_class($attachmentHeading, 'bcs-attachment-page-068');
            return;
        }

        $start = self::direct_child_under($attachmentHeading, $parent) ?: $attachmentHeading;
        $stop = $proof instanceof DOMElement ? self::direct_child_under($proof, $parent) : null;
        if ($stop === $start) $stop = null;

        $wrapper = $dom->createElement('div');
        $wrapper->setAttribute('class', 'bcs-attachment-page-068');
        $parent->insertBefore($wrapper, $start);
        $current = $start;
        while ($current && $current !== $stop) {
            $next = $current->nextSibling;
            $wrapper->appendChild($current);
            $current = $next;
        }
    }

    public static function agreement_css(): string {
        return '
            @page{margin:92pt 39.7pt 64pt 39.7pt}
            html,body{margin:0!important;padding:0!important;background:#fff!important;color:#172033!important}
            .bcs-document-052,.bcs-document-066,.bcs-document-067,.bcs-document-068{background:#fff!important;color:#172033!important}
            .bcs-document-header,.bcs-document-footer{display:none!important}
            .bcs-document-content{background:#fff!important;color:#172033!important}

            .bcs-attachment-page-068{page-break-before:always!important;break-before:page!important;page-break-after:always!important;break-after:page!important;page-break-inside:avoid!important;margin:0!important;padding:0!important;font-size:7.15pt!important;line-height:1.10!important;background:#fff!important;color:#172033!important}
            .bcs-attachment-page-068 .bcs-attachment-start-055,.bcs-attachment-page-068 .bcs-page-break-before{page-break-before:auto!important;break-before:auto!important}
            .bcs-attachment-page-068 h1,.bcs-attachment-page-068 h2{font-size:10pt!important;line-height:1.10!important;margin:0 0 4px!important;padding:0 0 2px!important;text-align:center!important;color:#c2410c!important}
            .bcs-attachment-page-068 h3{font-size:7.65pt!important;line-height:1.08!important;margin:3px 0 1.5px!important;padding:0!important;color:#ea580c!important;border-bottom:0!important}
            .bcs-attachment-page-068 p{font-size:7.05pt!important;line-height:1.08!important;margin:0 0 2px!important;padding:0!important}
            .bcs-attachment-page-068 table{font-size:6.85pt!important;line-height:1.06!important;margin:2px 0 3px!important;page-break-inside:avoid!important;border-collapse:collapse!important}
            .bcs-attachment-page-068 tr{page-break-inside:avoid!important}
            .bcs-attachment-page-068 td,.bcs-attachment-page-068 th{padding:1.4px 2.2px!important;line-height:1.06!important;border:0.6px solid #f0a36f!important}
            .bcs-attachment-page-068 br{line-height:1!important}

            .bcs-proof-page-068,.proof,.bcs-proof-page,.bcs-proof-start-057{page-break-before:always!important;break-before:page!important;margin-top:0!important}

            @media screen{
                .bcs-document-header{display:block!important;text-align:center!important;background:#fff!important;border-bottom:2px solid #f97316!important}
                .bcs-document-header img{display:block!important;margin:0 auto!important}
                .bcs-document-footer{display:block!important;background:#fff!important;color:#f97316!important;border:0!important}
                .bcs-document-footer-rule{background:#f97316!important;background-color:#f97316!important}
                .bcs-document-footer-text{color:#f97316!important;background:transparent!important}
                .bcs-attachment-page-068,.bcs-proof-page-068{page-break-before:auto!important;page-break-after:auto!important;margin-top:28px!important}
            }
        ';
    }

    public static function prepare_agreement_html(string $html): string {
        if (trim($html) === '' || !self::is_agreement($html)) return $html;
        if (!class_exists('DOMDocument')) return '<style id="'.self::STYLE_ID.'">'.self::agreement_css().'</style>'.$html;

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
        self::remove_previous_page_rules($dom);
        self::mark_attachment_and_proof($dom, $xpath);

        $document = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' bcs-document-052 ')]")->item(0);
        if ($document instanceof DOMElement) {
            self::add_class($document, 'bcs-document-068');
            $document->setAttribute('data-bcs-pdf-decoration', 'canvas-page-script-068');
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

    private static function logo_temp_path(): string {
        if (!class_exists('BCS_Release_066')) return '';
        $uri = BCS_Release_066::logo_data_uri();
        if (!preg_match('~^data:image/(png|jpeg|gif|webp);base64,(.+)$~s', $uri, $match)) return '';
        $bytes = base64_decode(preg_replace('/\s+/', '', $match[2]), true);
        if (!is_string($bytes) || $bytes === '') return '';
        $ext = $match[1] === 'jpeg' ? 'jpg' : $match[1];
        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'bcs-agreement-logo-'.md5($bytes).'.'.$ext;
        if (!is_readable($path) && @file_put_contents($path, $bytes, LOCK_EX) === false) return '';
        return $path;
    }

    private static function text_width(object $fontMetrics, string $text, mixed $font, float $size): float {
        if (method_exists($fontMetrics, 'getTextWidth')) return (float)$fontMetrics->getTextWidth($text, $font, $size);
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
        return array_slice($lines ?: ['Basketmania Camp'], 0, 3);
    }

    public static function apply_canvas_header_footer(object $pdf, string $html, string $title = ''): void {
        if (!self::is_agreement($html, $title) || !method_exists($pdf, 'getCanvas') || !method_exists($pdf, 'getFontMetrics')) return;
        $canvas = $pdf->getCanvas();
        if (!is_object($canvas) || !method_exists($canvas, 'page_script')) return;

        $footerText = class_exists('BCS_Release_067') ? BCS_Release_067::footer_text_from_html($html) : '';
        if ($footerText === '') $footerText = 'Basketmania Camp';
        $logoPath = self::logo_temp_path();
        $orange = [249 / 255, 115 / 255, 22 / 255];
        $white = [1, 1, 1];

        $canvas->page_script(static function ($pageNumber, $pageCount, $pageCanvas, $fontMetrics) use ($footerText, $logoPath, $orange, $white): void {
            $width = (float)$pageCanvas->get_width();
            $height = (float)$pageCanvas->get_height();

            $logoWidth = 112.0;
            $logoHeight = 47.8;
            $logoX = max(0.0, ($width - $logoWidth) / 2);
            if ($logoPath !== '' && method_exists($pageCanvas, 'image')) {
                try { $pageCanvas->image($logoPath, $logoX, 7.0, $logoWidth, $logoHeight); } catch (Throwable $e) {}
            }
            if (method_exists($pageCanvas, 'line')) $pageCanvas->line(39.5, 66.0, $width - 39.5, 66.0, $orange, 1.1);

            $footerHeight = 47.0;
            $footerY = $height - $footerHeight;
            if (method_exists($pageCanvas, 'filled_rectangle')) $pageCanvas->filled_rectangle(0.0, $footerY, $width, $footerHeight, $white);
            if (method_exists($pageCanvas, 'line')) $pageCanvas->line(28.0, $footerY + 4.0, $width - 28.0, $footerY + 4.0, $orange, 0.9);

            $font = method_exists($fontMetrics, 'getFont') ? $fontMetrics->getFont('DejaVu Sans', 'normal') : null;
            $size = 6.7;
            $lines = self::wrap_text($footerText, $fontMetrics, $font, $size, $width - 58.0);
            $lineHeight = 8.2;
            $textHeight = count($lines) * $lineHeight;
            $y = $footerY + 10.0 + max(0.0, (($footerHeight - 13.0) - $textHeight) / 2);
            foreach ($lines as $line) {
                $textWidth = self::text_width($fontMetrics, $line, $font, $size);
                $x = max(20.0, ($width - $textWidth) / 2);
                if (method_exists($pageCanvas, 'text')) $pageCanvas->text($x, $y, $line, $font, $size, $orange);
                $y += $lineHeight;
            }
        });
    }
}
