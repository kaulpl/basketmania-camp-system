<?php
if (!defined('ABSPATH')) exit;

/**
 * Wersja 0.69:
 * - Dompdf pracuje w trybie print, a nie w domyślnym trybie screen,
 * - statyczny nagłówek i stopka są fizycznie usuwane z HTML przekazywanego do PDF,
 * - jedna końcowa reguła @page rezerwuje bezpieczny obszar pod nagłówkiem Canvas,
 * - Załącznik nr 1 zawsze rozpoczyna i kończy osobną stronę,
 * - czcionka Załącznika nr 1 jest większa o 1 pt względem 0.68.
 */
final class BCS_Release_069 {
    private const STYLE_ID = 'bcs-agreement-style-069';

    public static function init(): void {
        self::replace_preview_handlers();
    }

    private static function replace_preview_handlers(): void {
        if (!class_exists('BCS_Release_068')) return;

        remove_action('admin_post_bcs_agreement_view', ['BCS_Release_068', 'render_agreement_view'], 0);
        remove_action('admin_post_nopriv_bcs_agreement_view', ['BCS_Release_068', 'render_agreement_view'], 0);
        add_action('admin_post_bcs_agreement_view', [__CLASS__, 'render_agreement_view'], 0);
        add_action('admin_post_nopriv_bcs_agreement_view', [__CLASS__, 'render_agreement_view'], 0);

        remove_action('admin_post_bcs_agreement_version_preview_054', ['BCS_Release_068', 'render_version_preview']);
        add_action('admin_post_bcs_agreement_version_preview_054', [__CLASS__, 'render_version_preview']);
    }

    public static function render_agreement_view(): void {
        ob_start([__CLASS__, 'buffer_preview_html']);
        BCS_Release_068::render_agreement_view();
    }

    public static function render_version_preview(): void {
        ob_start([__CLASS__, 'buffer_preview_html']);
        BCS_Release_068::render_version_preview();
    }

    public static function buffer_preview_html(string $html): string {
        return self::prepare_preview_html($html);
    }

    public static function is_agreement_document(string $html, string $title = ''): bool {
        if (class_exists('BCS_Release_067')) {
            return BCS_Release_067::is_agreement_document($html, $title);
        }
        return stripos($title, 'umowa') !== false
            || stripos($html, 'UMOWA UDZIAŁU') !== false
            || stripos($html, 'KARTA KWALIFIKACYJNA') !== false;
    }

    private static function load_document(string $html): ?DOMDocument {
        if (!class_exists('DOMDocument')) return null;
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $ok = $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $ok ? $dom : null;
    }

    private static function class_query(string $class): string {
        return "//*[contains(concat(' ', normalize-space(@class), ' '), ' ".$class." ')]";
    }

    private static function add_class(DOMElement $element, string $class): void {
        $classes = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [];
        if (!in_array($class, $classes, true)) $classes[] = $class;
        $element->setAttribute('class', trim(implode(' ', array_filter($classes))));
    }

    private static function append_style(DOMElement $element, string $declaration): void {
        $style = trim($element->getAttribute('style'));
        if ($style !== '' && !str_ends_with($style, ';')) $style .= ';';
        $element->setAttribute('style', $style.$declaration);
    }

    private static function remove_page_rules(DOMDocument $dom): void {
        foreach ($dom->getElementsByTagName('style') as $style) {
            if (!$style instanceof DOMElement || $style->getAttribute('id') === self::STYLE_ID) continue;
            $css = (string)$style->textContent;
            $clean = preg_replace('~@page\s*(?:[^\{]*)\{[^\{\}]*\}~is', '', $css);
            if (!is_string($clean) || $clean === $css) continue;
            while ($style->firstChild) $style->removeChild($style->firstChild);
            $style->appendChild($dom->createTextNode($clean));
        }
    }

    private static function remove_static_header_footer(DOMDocument $dom, DOMXPath $xpath): void {
        $queries = [
            self::class_query('bcs-document-header'),
            self::class_query('bcs-agreement-header'),
            self::class_query('bcs-document-footer'),
            self::class_query('bcs-agreement-footer'),
        ];
        $nodes = [];
        foreach ($queries as $query) {
            foreach ($xpath->query($query) as $node) $nodes[] = $node;
        }
        foreach ($nodes as $node) {
            if ($node instanceof DOMNode && $node->parentNode) $node->parentNode->removeChild($node);
        }
    }

    private static function strengthen_page_breaks(DOMXPath $xpath): void {
        foreach ($xpath->query(self::class_query('bcs-attachment-page-068')) as $attachment) {
            if (!$attachment instanceof DOMElement) continue;
            self::add_class($attachment, 'bcs-attachment-page-069');
            self::append_style(
                $attachment,
                'page-break-before:always;break-before:page;page-break-after:always;break-after:page;page-break-inside:avoid;margin-top:0;padding-top:0;'
            );
        }

        foreach ([
            'bcs-proof-page-068',
            'bcs-proof-page',
            'bcs-proof-start-057',
            'proof',
        ] as $class) {
            foreach ($xpath->query(self::class_query($class)) as $proof) {
                if (!$proof instanceof DOMElement) continue;
                self::add_class($proof, 'bcs-proof-page-069');
                self::append_style($proof, 'page-break-before:always;break-before:page;margin-top:0;padding-top:0;');
            }
        }
    }

    private static function insert_style(DOMDocument $dom, ?DOMElement $document, string $css): void {
        foreach ($dom->getElementsByTagName('style') as $oldStyle) {
            if ($oldStyle instanceof DOMElement && $oldStyle->getAttribute('id') === self::STYLE_ID && $oldStyle->parentNode) {
                $oldStyle->parentNode->removeChild($oldStyle);
                break;
            }
        }

        $style = $dom->createElement('style');
        $style->setAttribute('id', self::STYLE_ID);
        $style->appendChild($dom->createTextNode($css));
        $head = $dom->getElementsByTagName('head')->item(0);
        if ($head) $head->appendChild($style);
        elseif ($document && $document->parentNode) $document->parentNode->insertBefore($style, $document);
    }

    private static function save_document(DOMDocument $dom): string {
        $result = (string)$dom->saveHTML();
        return preg_replace('/^<\?xml[^>]*>\s*/i', '', $result) ?? $result;
    }

    public static function pdf_css(): string {
        return '
            @page{margin:104pt 39.7pt 66pt 39.7pt}
            html,body{margin:0!important;padding:0!important;background:#fff!important;color:#172033!important}
            .bcs-document-052,.bcs-document-066,.bcs-document-067,.bcs-document-068,.bcs-document-069{background:#fff!important;color:#172033!important}
            .bcs-document-header,.bcs-agreement-header,.bcs-document-footer,.bcs-agreement-footer{display:none!important}
            .bcs-document-content{margin:0!important;padding:0!important;background:#fff!important;color:#172033!important}

            .bcs-attachment-page-068,.bcs-attachment-page-069{page-break-before:always!important;break-before:page!important;page-break-after:always!important;break-after:page!important;page-break-inside:avoid!important;margin:0!important;padding:0!important;font-size:8.15pt!important;line-height:1.02!important;background:#fff!important;color:#172033!important}
            .bcs-attachment-page-069 .bcs-attachment-start-055,.bcs-attachment-page-069 .bcs-page-break-before{page-break-before:auto!important;break-before:auto!important}
            .bcs-attachment-page-069 h1,.bcs-attachment-page-069 h2{font-size:11pt!important;line-height:1.04!important;margin:0 0 2px!important;padding:0 0 1px!important;text-align:center!important;color:#c2410c!important}
            .bcs-attachment-page-069 h3{font-size:8.65pt!important;line-height:1.02!important;margin:2px 0 1px!important;padding:0!important;color:#ea580c!important;border-bottom:0!important}
            .bcs-attachment-page-069 p{font-size:8.05pt!important;line-height:1.02!important;margin:0 0 1px!important;padding:0!important}
            .bcs-attachment-page-069 table{font-size:7.85pt!important;line-height:1.01!important;margin:1px 0 2px!important;page-break-inside:avoid!important;border-collapse:collapse!important}
            .bcs-attachment-page-069 tr{page-break-inside:avoid!important}
            .bcs-attachment-page-069 td,.bcs-attachment-page-069 th{padding:0.8px 1.6px!important;line-height:1.01!important;border:0.6px solid #f0a36f!important}
            .bcs-attachment-page-069 br{line-height:0.95!important}

            .bcs-proof-page-069{page-break-before:always!important;break-before:page!important;margin-top:0!important;padding-top:0!important}
        ';
    }

    public static function preview_css(): string {
        return '
            .bcs-document-header{text-align:center!important}
            .bcs-document-header img{display:block!important;margin-left:auto!important;margin-right:auto!important}
            .bcs-document-footer{background:#fff!important;color:#f97316!important}
            .bcs-document-footer-rule{background:#f97316!important;background-color:#f97316!important}
            .bcs-document-footer-text{color:#f97316!important;background:transparent!important}
            .bcs-attachment-page-068,.bcs-attachment-page-069{font-size:8.15pt!important;line-height:1.08!important}
            .bcs-attachment-page-069 h1,.bcs-attachment-page-069 h2{font-size:11pt!important}
            .bcs-attachment-page-069 h3{font-size:8.65pt!important}
            .bcs-attachment-page-069 p{font-size:8.05pt!important}
            .bcs-attachment-page-069 table{font-size:7.85pt!important}
        ';
    }

    public static function prepare_pdf_html(string $html, string $title = ''): string {
        if (trim($html) === '' || !self::is_agreement_document($html, $title)) return $html;
        $dom = self::load_document($html);
        if (!$dom) return '<style id="'.self::STYLE_ID.'">'.self::pdf_css().'</style>'.$html;

        $xpath = new DOMXPath($dom);
        self::remove_page_rules($dom);
        self::strengthen_page_breaks($xpath);
        self::remove_static_header_footer($dom, $xpath);

        $document = $xpath->query(self::class_query('bcs-document-052'))->item(0);
        if (!$document instanceof DOMElement) $document = $xpath->query(self::class_query('bcs-document-068'))->item(0);
        if ($document instanceof DOMElement) {
            self::add_class($document, 'bcs-document-069');
            $document->setAttribute('data-bcs-pdf-decoration', 'canvas-page-script-069');
        }

        self::insert_style($dom, $document instanceof DOMElement ? $document : null, self::pdf_css());
        return self::save_document($dom);
    }

    public static function prepare_preview_html(string $html): string {
        if (trim($html) === '' || !self::is_agreement_document($html)) return $html;
        $dom = self::load_document($html);
        if (!$dom) return '<style id="'.self::STYLE_ID.'">'.self::preview_css().'</style>'.$html;

        $xpath = new DOMXPath($dom);
        self::strengthen_page_breaks($xpath);
        $document = $xpath->query(self::class_query('bcs-document-052'))->item(0);
        if ($document instanceof DOMElement) self::add_class($document, 'bcs-document-069');
        self::insert_style($dom, $document instanceof DOMElement ? $document : null, self::preview_css());
        return self::save_document($dom);
    }

    public static function footer_text_from_html(string $html): string {
        if (class_exists('BCS_Release_067')) return BCS_Release_067::footer_text_from_html($html);
        return '';
    }

    public static function apply_canvas_header_footer(object $pdf, string $sourceHtml, string $title = ''): void {
        if (class_exists('BCS_Release_068')) {
            BCS_Release_068::apply_canvas_header_footer($pdf, $sourceHtml, $title);
        }
    }
}
