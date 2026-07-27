<?php
if (!defined('ABSPATH')) exit;

/**
 * Niezależny generator umów V2.
 *
 * Nie korzysta z kolejnych dekoratorów 0.52–0.69 ani z Canvas. Buduje jeden,
 * zamknięty dokument Dompdf z nagłówkiem i stopką jako bezpośrednimi dziećmi
 * BODY. Marginesy @page są znane przed składem tekstu, dzięki czemu treść nie
 * może wejść w obszar logo lub stopki.
 */
final class BCS_Agreement_PDF_V2 {
    private const STYLE_ID = 'bcs-agreement-v2-style';
    private const LOGO_B64_FILE = 'assets/images/logo-basketmania-camp-color-retina.png.b64';

    public static function init(): void {
        self::replace_preview_handlers();
    }

    private static function replace_preview_handlers(): void {
        if (class_exists('BCS_Release_069')) {
            remove_action('admin_post_bcs_agreement_view', ['BCS_Release_069', 'render_agreement_view'], 0);
            remove_action('admin_post_nopriv_bcs_agreement_view', ['BCS_Release_069', 'render_agreement_view'], 0);
            remove_action('admin_post_bcs_agreement_version_preview_054', ['BCS_Release_069', 'render_version_preview']);
        }

        add_action('admin_post_bcs_agreement_view', [__CLASS__, 'render_agreement_view'], 0);
        add_action('admin_post_nopriv_bcs_agreement_view', [__CLASS__, 'render_agreement_view'], 0);
        add_action('admin_post_bcs_agreement_version_preview_054', [__CLASS__, 'render_version_preview']);
    }

    public static function render_agreement_view(): void {
        ob_start([__CLASS__, 'buffer_preview_html']);
        BCS_Release_052::render_agreement_view();
    }

    public static function render_version_preview(): void {
        ob_start([__CLASS__, 'buffer_preview_html']);
        BCS_Release_054::render_version_preview();
    }

    public static function buffer_preview_html(string $html): string {
        return self::prepare_preview_html($html, self::preview_registration_id());
    }

    private static function preview_registration_id(): int {
        $id = absint($_GET['registration_id'] ?? $_GET['registration'] ?? 0);
        if ($id) return $id;

        $agreement_id = absint($_GET['agreement'] ?? 0);
        if (!$agreement_id) return 0;

        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !class_exists('BCS_DB')) return 0;
        return (int)$wpdb->get_var($wpdb->prepare(
            'SELECT registration_id FROM '.BCS_DB::table('agreements').' WHERE id=%d LIMIT 1',
            $agreement_id
        ));
    }

    public static function is_agreement_document(string $html, string $title = ''): bool {
        $plain = self::normalized(strip_tags($html));
        return str_contains(self::normalized($title), 'UMOWA')
            || str_contains($plain, 'UMOWA UDZIAŁU W OBOZIE')
            || str_contains($plain, 'KARTA KWALIFIKACYJNA UCZESTNIKA WYPOCZYNKU');
    }

    private static function normalized(string $text): string {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return mb_strtoupper((string)preg_replace('/\s+/u', ' ', trim($text)), 'UTF-8');
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

    private static function load_fragment(string $html): ?DOMDocument {
        if (!class_exists('DOMDocument')) return null;
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $ok = $dom->loadHTML(
            '<?xml encoding="utf-8" ?><div id="bcs-v2-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $ok ? $dom : null;
    }

    private static function inner_html(DOMNode $node): string {
        $html = '';
        foreach ($node->childNodes as $child) $html .= $node->ownerDocument->saveHTML($child);
        return $html;
    }

    private static function class_query(string $class): string {
        return "//*[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]";
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

    private static function extract_body(string $html): string {
        $dom = self::load_document($html);
        if (!$dom) return $html;
        $xpath = new DOMXPath($dom);

        foreach (['bcs-document-content', 'bcs-agreement-content'] as $class) {
            $content = $xpath->query(self::class_query($class))->item(0);
            if ($content instanceof DOMNode) return self::inner_html($content);
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        return $body instanceof DOMNode ? self::inner_html($body) : $html;
    }

    private static function extract_existing_footer(string $html): string {
        $dom = self::load_document($html);
        if (!$dom) return '';
        $xpath = new DOMXPath($dom);
        foreach (['bcs-document-footer-text', 'bcs-document-footer', 'bcs-agreement-footer'] as $class) {
            $node = $xpath->query(self::class_query($class))->item(0);
            if (!$node) continue;
            $text = trim((string)preg_replace('/\s+/u', ' ', html_entity_decode($node->textContent ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($text !== '') return $text;
        }
        return '';
    }

    private static function organizer_identity(int $registration_id, string $source_html): string {
        if ($registration_id > 0 && class_exists('BCS_DB')) {
            global $wpdb;
            if (isset($wpdb) && is_object($wpdb)) {
                $row = $wpdb->get_row($wpdb->prepare(
                    'SELECT o.name,o.legal_form,o.address,o.nip,o.regon,o.krs,o.email,o.phone
                     FROM '.BCS_DB::table('registrations').' r
                     JOIN '.BCS_DB::table('camps').' c ON c.id=r.camp_id
                     LEFT JOIN '.BCS_DB::table('organizers').' o ON o.id=c.organizer_id
                     WHERE r.id=%d LIMIT 1',
                    $registration_id
                ));
                if ($row) {
                    $parts = [];
                    $name = trim((string)$row->name.' '.(string)$row->legal_form);
                    if ($name !== '') $parts[] = $name;
                    if (!empty($row->address)) $parts[] = trim((string)$row->address);
                    if (!empty($row->nip)) $parts[] = 'NIP: '.trim((string)$row->nip);
                    if (!empty($row->regon)) $parts[] = 'REGON: '.trim((string)$row->regon);
                    if (!empty($row->krs)) $parts[] = 'KRS: '.trim((string)$row->krs);
                    if (!empty($row->email)) $parts[] = trim((string)$row->email);
                    if (!empty($row->phone)) $parts[] = trim((string)$row->phone);
                    if ($parts) return implode(' · ', $parts);
                }
            }
        }

        $footer = self::extract_existing_footer($source_html);
        return $footer !== '' ? $footer : 'Basketmania Camp';
    }

    private static function logo_data_uri(): string {
        static $uri = null;
        if (is_string($uri)) return $uri;
        $path = BCS_DIR.self::LOGO_B64_FILE;
        if (!is_readable($path)) return $uri = '';
        $base64 = preg_replace('/\s+/', '', (string)file_get_contents($path));
        if ($base64 === '' || base64_decode($base64, true) === false) return $uri = '';
        return $uri = 'data:image/png;base64,'.$base64;
    }

    private static function is_attachment_heading(DOMElement $node): bool {
        $text = self::normalized($node->textContent ?? '');
        return (str_contains($text, 'ZAŁĄCZNIK NR 1') || str_contains($text, 'ZALACZNIK NR 1'))
            && str_contains($text, 'KARTA KWALIFIKACYJNA UCZESTNIKA WYPOCZYNKU');
    }

    private static function evidence_node(DOMXPath $xpath): ?DOMElement {
        foreach (['proof', 'bcs-proof-page', 'bcs-proof-start-057', 'bcs-proof-page-068', 'bcs-proof-page-069'] as $class) {
            $node = $xpath->query(self::class_query($class))->item(0);
            if ($node instanceof DOMElement) return $node;
        }
        foreach ($xpath->query('//h1|//h2|//h3') as $heading) {
            if (!$heading instanceof DOMElement) continue;
            $text = self::normalized($heading->textContent ?? '');
            if (str_contains($text, 'SEKCJA DOWODOWA ZAWARCIA UMOWY')
                || str_contains($text, 'POTWIERDZENIE ZAWARCIA UMOWY')) {
                return $heading;
            }
        }
        return null;
    }

    private static function direct_child_under(DOMNode $node, DOMNode $ancestor): ?DOMNode {
        $current = $node;
        while ($current->parentNode && $current->parentNode !== $ancestor) $current = $current->parentNode;
        return $current->parentNode === $ancestor ? $current : null;
    }

    private static function unwrap_legacy_attachment(DOMXPath $xpath): void {
        $nodes = [];
        foreach (['bcs-attachment-page-068', 'bcs-attachment-page-069'] as $class) {
            foreach ($xpath->query(self::class_query($class)) as $node) $nodes[] = $node;
        }
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement || !$node->parentNode) continue;
            $parent = $node->parentNode;
            while ($node->firstChild) $parent->insertBefore($node->firstChild, $node);
            $parent->removeChild($node);
        }
    }

    private static function clean_and_mark_sections(string $body): string {
        $dom = self::load_fragment($body);
        if (!$dom) return $body;
        $xpath = new DOMXPath($dom);
        $root = $dom->getElementById('bcs-v2-root');
        if (!$root) return $body;

        foreach (['style', 'script', 'link'] as $tag) {
            $nodes = [];
            foreach ($dom->getElementsByTagName($tag) as $node) $nodes[] = $node;
            foreach ($nodes as $node) if ($node->parentNode) $node->parentNode->removeChild($node);
        }
        foreach (['bcs-document-header', 'bcs-agreement-header', 'bcs-document-footer', 'bcs-agreement-footer'] as $class) {
            $nodes = [];
            foreach ($xpath->query(self::class_query($class)) as $node) $nodes[] = $node;
            foreach ($nodes as $node) if ($node->parentNode) $node->parentNode->removeChild($node);
        }
        foreach ($xpath->query('//*[@style]') as $node) {
            if ($node instanceof DOMElement) $node->removeAttribute('style');
        }

        self::unwrap_legacy_attachment($xpath);
        $xpath = new DOMXPath($dom);

        $evidence = self::evidence_node($xpath);
        if ($evidence instanceof DOMElement) {
            if (!$evidence->parentNode || $evidence->parentNode === $root || self::has_class($evidence, 'proof')) {
                self::add_class($evidence, 'bcs-v2-evidence');
            } else {
                $parent = $evidence->parentNode;
                $wrapper = $dom->createElement('section');
                $wrapper->setAttribute('class', 'bcs-v2-evidence');
                $parent->insertBefore($wrapper, $evidence);
                $current = $evidence;
                while ($current) {
                    $next = $current->nextSibling;
                    $wrapper->appendChild($current);
                    $current = $next;
                }
                $evidence = $wrapper;
            }
        }

        $attachmentHeading = null;
        foreach ($xpath->query('//h1|//h2|//h3') as $heading) {
            if ($heading instanceof DOMElement && self::is_attachment_heading($heading)) {
                $attachmentHeading = $heading;
                break;
            }
        }

        if ($attachmentHeading instanceof DOMElement && $attachmentHeading->parentNode) {
            $parent = $attachmentHeading->parentNode;
            $start = self::direct_child_under($attachmentHeading, $parent) ?: $attachmentHeading;
            $stop = $evidence instanceof DOMElement ? self::direct_child_under($evidence, $parent) : null;
            if ($stop === $start) $stop = null;

            $wrapper = $dom->createElement('section');
            $wrapper->setAttribute('class', 'bcs-v2-attachment');
            $parent->insertBefore($wrapper, $start);
            $current = $start;
            while ($current && $current !== $stop) {
                $next = $current->nextSibling;
                $wrapper->appendChild($current);
                $current = $next;
            }
        }

        return self::inner_html($root);
    }

    public static function pdf_css(): string {
        return '
            @page{margin:32mm 15mm 20mm 15mm}
            html,body{margin:0;padding:0;background:#fff;color:#172033;font-family:"DejaVu Sans",Arial,sans-serif;font-size:10pt;line-height:1.38}
            .bcs-v2-header{position:fixed;top:-25mm;left:0;right:0;height:18mm;box-sizing:border-box;text-align:center;border-bottom:1.4px solid #f97316;background:#fff}
            .bcs-v2-header img{display:block;height:14mm;width:auto;max-width:58mm;margin:0 auto;object-fit:contain}
            .bcs-v2-header-fallback{font-size:15pt;font-weight:700;color:#172033;line-height:14mm}
            .bcs-v2-footer{position:fixed;bottom:-15mm;left:0;right:0;height:10.5mm;box-sizing:border-box;border-top:1.2px solid #f97316;padding-top:2.2mm;text-align:center;color:#f97316;background:#fff;font-size:7.2pt;line-height:1.25}
            .bcs-v2-content{display:block;margin:0;padding:0;background:#fff;color:#172033}
            .bcs-v2-content *{box-sizing:border-box}
            .bcs-v2-content h1{font-size:16pt;line-height:1.2;text-align:center;margin:0 0 10px;color:#172033}
            .bcs-v2-content h2{font-size:11.5pt;line-height:1.25;margin:11px 0 5px;padding:0 0 2px;color:#c2410c;border-bottom:1px solid #fed7aa;page-break-after:avoid}
            .bcs-v2-content h3{font-size:10.5pt;line-height:1.2;margin:8px 0 4px;color:#ea580c;page-break-after:avoid}
            .bcs-v2-content p{margin:0 0 5px}
            .bcs-v2-content strong,.bcs-v2-content b{font-weight:700;color:#c2410c}
            .bcs-v2-content ol,.bcs-v2-content ul{margin:4px 0 7px 18px;padding:0}
            .bcs-v2-content li{margin:0 0 3px}
            .bcs-v2-content table{width:100%;border-collapse:collapse;margin:6px 0 9px;font-size:9.2pt;page-break-inside:auto;background:#fff}
            .bcs-v2-content tr{page-break-inside:avoid}
            .bcs-v2-content td,.bcs-v2-content th{border:1px solid #f0a36f;padding:4px 5px;vertical-align:top;background:#fff;color:#172033}
            .bcs-v2-content th{font-weight:700;color:#c2410c}
            .bcs-v2-attachment{page-break-before:always;break-before:page;page-break-inside:avoid;margin:0;padding:0;font-size:9pt;line-height:1.08;background:#fff;color:#172033}
            .bcs-v2-attachment h1,.bcs-v2-attachment h2{font-size:11pt;line-height:1.08;margin:0 0 3px;padding:0 0 1px;text-align:center;color:#c2410c}
            .bcs-v2-attachment h3{font-size:9pt;line-height:1.05;margin:2px 0 1px;padding:0;color:#ea580c;border:0}
            .bcs-v2-attachment p{font-size:8.8pt;line-height:1.05;margin:0 0 1.5px;padding:0}
            .bcs-v2-attachment table{font-size:8.45pt;line-height:1.03;margin:1px 0 2px;page-break-inside:avoid;border-collapse:collapse}
            .bcs-v2-attachment td,.bcs-v2-attachment th{padding:1.2px 2px;line-height:1.03;border:0.7px solid #f0a36f}
            .bcs-v2-attachment tr{page-break-inside:avoid}
            .bcs-v2-evidence{page-break-before:always;break-before:page;margin:0;padding:0 0 0 9px;border:0;border-left:3px solid #f97316;background:#fff;color:#172033}
            .bcs-v2-evidence h1,.bcs-v2-evidence h2{margin:0 0 10px;color:#c2410c}
        ';
    }

    public static function preview_css(): string {
        return self::pdf_css().'
            @media screen{
                html,body{background:#f4f6f8;padding:18px}
                body{max-width:820px;margin:0 auto;background:#fff;box-shadow:0 10px 30px rgba(23,32,51,.10)}
                .bcs-v2-header,.bcs-v2-footer{position:static;height:auto;background:#fff}
                .bcs-v2-header{padding:13px 30px 10px;border-bottom:1.4px solid #f97316}
                .bcs-v2-header img{height:54px;max-width:260px}
                .bcs-v2-footer{padding:10px 28px 13px;border-top:1.2px solid #f97316;color:#f97316}
                .bcs-v2-content{padding:28px 36px 34px}
                .bcs-v2-attachment,.bcs-v2-evidence{page-break-before:auto;break-before:auto;margin-top:28px}
            }
        ';
    }

    private static function build_document(string $source_html, string $title, int $registration_id, bool $preview): string {
        $body = self::clean_and_mark_sections(self::extract_body($source_html));
        $footer = self::organizer_identity($registration_id, $source_html);
        $logo = self::logo_data_uri();
        $logoHtml = $logo !== ''
            ? '<img src="'.esc_attr($logo).'" alt="Basketmania Camp">'
            : '<div class="bcs-v2-header-fallback">Basketmania Camp</div>';
        $css = $preview ? self::preview_css() : self::pdf_css();

        return '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'
            .esc_html($title).'</title><style id="'.self::STYLE_ID.'">'.$css.'</style></head><body class="bcs-agreement-v2">'
            .'<header class="bcs-v2-header">'.$logoHtml.'</header>'
            .'<footer class="bcs-v2-footer">'.esc_html($footer).'</footer>'
            .'<main class="bcs-v2-content">'.$body.'</main>'
            .'</body></html>';
    }

    public static function prepare_pdf_html(string $html, string $title = '', int $registration_id = 0): string {
        if (trim($html) === '' || !self::is_agreement_document($html, $title)) return $html;
        return self::build_document($html, $title !== '' ? $title : 'Umowa', $registration_id, false);
    }

    public static function prepare_preview_html(string $html, int $registration_id = 0): string {
        if (trim($html) === '' || !self::is_agreement_document($html)) return $html;
        $title = 'Podgląd umowy';
        $dom = self::load_document($html);
        if ($dom) {
            $titleNode = $dom->getElementsByTagName('title')->item(0);
            if ($titleNode && trim((string)$titleNode->textContent) !== '') $title = trim((string)$titleNode->textContent);
        }
        return self::build_document($html, $title, $registration_id, true);
    }
}
