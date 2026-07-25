<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_052 {
    private const LOGO_B64_FILE = 'assets/images/logo-basketmania-camp-color-retina.png.b64';

    public static function init(): void {
        remove_action('admin_post_bcs_agreement_view', ['BCS_Release_051', 'render_agreement_view'], 0);
        remove_action('admin_post_nopriv_bcs_agreement_view', ['BCS_Release_051', 'render_agreement_view'], 0);
        remove_action('template_redirect', ['BCS_Release_051', 'intercept_agreement_download'], 1);

        add_action('admin_post_bcs_agreement_view', [__CLASS__, 'render_agreement_view'], 0);
        add_action('admin_post_nopriv_bcs_agreement_view', [__CLASS__, 'render_agreement_view'], 0);
        add_action('template_redirect', [__CLASS__, 'intercept_agreement_download'], 0);
    }

    private static function row(int $registration_id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT r.*,c.name camp_name,c.start_date,c.end_date,c.location,
                    o.name organizer_name,o.legal_form organizer_legal_form,o.address organizer_address,
                    o.nip organizer_nip,o.regon organizer_regon,o.krs organizer_krs,
                    o.email organizer_email,o.phone organizer_phone,
                    a.id agreement_real_id,a.agreement_number,a.html agreement_html,
                    a.status agreement_record_status,a.document_hash agreement_document_hash
             FROM ".BCS_DB::table('registrations')." r
             JOIN ".BCS_DB::table('camps')." c ON c.id=r.camp_id
             LEFT JOIN ".BCS_DB::table('organizers')." o ON o.id=c.organizer_id
             LEFT JOIN ".BCS_DB::table('agreements')." a ON a.id=r.agreement_id
             WHERE r.id=%d LIMIT 1",
            $registration_id
        )) ?: null;
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

    private static function load_fragment(string $html): ?DOMDocument {
        if (!class_exists('DOMDocument')) return null;
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $ok = $dom->loadHTML(
            '<?xml encoding="utf-8" ?><div id="bcs-052-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $ok ? $dom : null;
    }

    private static function inner_html(DOMNode $node): string {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument->saveHTML($child);
        }
        return $html;
    }

    private static function class_xpath(string $class): string {
        return "//*[contains(concat(' ', normalize-space(@class), ' '), ' ".$class." ')]";
    }

    private static function add_class(DOMElement $element, string $class): void {
        $classes = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [];
        if (!in_array($class, $classes, true)) $classes[] = $class;
        $element->setAttribute('class', trim(implode(' ', array_filter($classes))));
    }

    private static function normalized(string $text): string {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return mb_strtoupper((string)preg_replace('/\s+/u', ' ', trim($text)), 'UTF-8');
    }

    private static function extract_document_parts(string $html): array {
        $body = $html;
        $footer = '';
        $dom = self::load_fragment($html);
        if (!$dom) return [$body, $footer];
        $xpath = new DOMXPath($dom);

        $content = $xpath->query(self::class_xpath('bcs-document-content'))->item(0);
        if (!$content) $content = $xpath->query(self::class_xpath('bcs-agreement-content'))->item(0);
        if ($content) $body = self::inner_html($content);

        $footer_node = $xpath->query(self::class_xpath('bcs-document-footer'))->item(0);
        if (!$footer_node) $footer_node = $xpath->query(self::class_xpath('bcs-agreement-footer'))->item(0);
        if ($footer_node) $footer = trim(wp_strip_all_tags(self::inner_html($footer_node)));

        return [$body, $footer];
    }

    private static function apply_page_breaks(string $body): string {
        $dom = self::load_fragment($body);
        if (!$dom) return $body;
        $xpath = new DOMXPath($dom);

        $camp_break_added = false;
        $attachment_break_added = false;

        foreach ($xpath->query('//h1|//h2|//h3|//p') as $node) {
            if (!$node instanceof DOMElement) continue;
            $text = self::normalized($node->textContent ?? '');

            if (!$camp_break_added && str_contains($text, 'INFORMACJE O OBOZIE')) {
                self::add_class($node, 'bcs-page-break-before');
                self::add_class($node, 'bcs-keep-with-next');
                $camp_break_added = true;
            }

            if (!$attachment_break_added && (
                str_starts_with($text, 'ZAŁĄCZNIK NR 1')
                || str_starts_with($text, 'ZALACZNIK NR 1')
            )) {
                self::add_class($node, 'bcs-page-break-before');
                self::add_class($node, 'bcs-keep-with-next');
                $attachment_break_added = true;
            }
        }

        foreach ($xpath->query(self::class_xpath('proof')) as $proof) {
            if ($proof instanceof DOMElement) self::add_class($proof, 'bcs-proof-page');
        }

        $root = $dom->getElementById('bcs-052-root');
        return $root ? self::inner_html($root) : $body;
    }

    private static function company_identity(object $row): string {
        $parts = [];
        $name = trim((string)$row->organizer_name.' '.(string)$row->organizer_legal_form);
        if ($name !== '') $parts[] = $name;
        if (!empty($row->organizer_address)) $parts[] = (string)$row->organizer_address;
        if (!empty($row->organizer_nip)) $parts[] = 'NIP: '.(string)$row->organizer_nip;
        if (!empty($row->organizer_regon)) $parts[] = 'REGON: '.(string)$row->organizer_regon;
        if (!empty($row->organizer_krs)) $parts[] = 'KRS: '.(string)$row->organizer_krs;
        if (!empty($row->organizer_email)) $parts[] = (string)$row->organizer_email;
        if (!empty($row->organizer_phone)) $parts[] = (string)$row->organizer_phone;
        return implode(' · ', $parts);
    }

    private static function css(): string {
        return '<style id="bcs-agreement-style-052">
            @page{margin:27mm 14mm 20mm 14mm}
            html,body{margin:0;padding:0;background:#fff;color:#172033}
            .bcs-document-052{font-family:"DejaVu Sans",Arial,sans-serif;font-size:10pt;line-height:1.38;background:#fff;color:#172033}
            .bcs-document-header{position:fixed;top:-21mm;left:0;right:0;height:15mm;padding:1.5mm 0 2mm;border-bottom:1.5px solid #f97316;background:#fff;box-sizing:border-box}
            .bcs-document-header img{display:block;height:10.5mm;width:auto;max-width:58mm;object-fit:contain}
            .bcs-document-content{display:block;position:relative;background:#fff}
            .bcs-document-content h1{font-size:16pt;line-height:1.2;text-align:center;margin:0 0 10px;color:#172033}
            .bcs-document-content h2{font-size:11.5pt;line-height:1.25;margin:11px 0 5px;color:#c2410c;page-break-after:avoid}
            .bcs-document-content h3{font-size:10.5pt;margin:8px 0 4px;color:#172033;page-break-after:avoid}
            .bcs-document-content p{margin:0 0 5px}
            .bcs-document-content ol,.bcs-document-content ul{margin:4px 0 7px 18px;padding:0}
            .bcs-document-content li{margin:0 0 3px}
            .bcs-document-content table{width:100%;border-collapse:collapse;margin:6px 0 9px;font-size:9.2pt;page-break-inside:auto;background:#fff}
            .bcs-document-content tr{page-break-inside:avoid}
            .bcs-document-content td,.bcs-document-content th{border:1px solid #cfd5df;padding:4px 5px;vertical-align:top;background:#fff;color:#172033}
            .bcs-document-content th{font-weight:700}
            .bcs-document-footer{position:fixed;bottom:-14mm;left:0;right:0;min-height:10mm;border-top:1.2px solid #f97316;padding-top:2.3mm;font-size:7.7pt;line-height:1.3;color:#4b5563;text-align:center;background:#fff}
            .bcs-page-break-before{page-break-before:always;break-before:page}
            .bcs-keep-with-next{page-break-after:avoid;break-after:avoid-page}
            .proof,.bcs-proof-page{page-break-before:always;break-before:page;border:1.5px solid #f97316;padding:14px 16px;margin:0;box-sizing:border-box;background:#fff}
            .proof h2,.bcs-proof-page h2{font-size:14pt;color:#c2410c;margin:0 0 10px}
            .proof h3,.bcs-proof-page h3{font-size:10.5pt;color:#172033;margin:0 0 8px}
            .proof p,.bcs-proof-page p{margin:0 0 4px;line-height:1.3}
            .proof code,.bcs-proof-page code{font-family:"DejaVu Sans Mono",monospace;font-size:8pt;word-wrap:break-word}
            @media screen{
                html,body{background:#f4f6f8}
                body{padding:22px}
                .bcs-document-052{max-width:820px;margin:0 auto;background:#fff;box-shadow:0 10px 30px rgba(23,32,51,.10);border:1px solid #e6e8ec}
                .bcs-document-header,.bcs-document-footer{position:static;background:#fff}
                .bcs-document-header{height:auto;min-height:68px;padding:13px 34px;border-bottom:1.5px solid #f97316}
                .bcs-document-header img{height:42px;max-width:230px}
                .bcs-document-content{padding:30px 38px 34px;background:#fff}
                .bcs-document-footer{padding:12px 28px 15px;min-height:0;background:#fff}
                .bcs-page-break-before,.proof,.bcs-proof-page{page-break-before:auto;break-before:auto;margin-top:30px}
            }
        </style>';
    }

    private static function render_document(string $html, object $row): string {
        [$body, $footer] = self::extract_document_parts($html);
        $body = self::apply_page_breaks($body);
        if ($footer === '') $footer = self::company_identity($row);

        $logo = self::logo_data_uri();
        $logo_html = $logo !== ''
            ? '<img src="'.esc_attr($logo).'" alt="Basketmania Camp">'
            : '<strong style="font-size:15pt">Basketmania Camp</strong>';

        return self::css()
            .'<div class="bcs-document-052">'
            .'<div class="bcs-document-header">'.$logo_html.'</div>'
            .'<div class="bcs-document-content">'.$body.'</div>'
            .'<div class="bcs-document-footer">'.esc_html($footer).'</div>'
            .'</div>';
    }

    private static function html_for_stage(int $registration_id, string $stage): string {
        global $wpdb;
        $row = self::row($registration_id);
        if (!$row || empty($row->agreement_real_id)) return '';

        BCS_Release_051::repair_registration(
            $registration_id,
            $stage === 'signed' || in_array((string)$row->agreement_status, ['parent_signed','accepted'], true)
        );
        $row = self::row($registration_id);
        if (!$row) return '';

        $html = '';
        if ($stage === 'signed' || in_array((string)$row->agreement_status, ['parent_signed','accepted'], true)) {
            $html = (string)$wpdb->get_var($wpdb->prepare(
                "SELECT html FROM ".BCS_DB::table('agreement_versions')."
                 WHERE agreement_id=%d AND stage='signed' ORDER BY id DESC LIMIT 1",
                (int)$row->agreement_real_id
            ));
        } elseif (in_array($stage, ['draft','sent'], true)) {
            $html = (string)$wpdb->get_var($wpdb->prepare(
                "SELECT html FROM ".BCS_DB::table('agreement_versions')."
                 WHERE agreement_id=%d AND stage=%s ORDER BY id DESC LIMIT 1",
                (int)$row->agreement_real_id,
                $stage
            ));
        }
        if (trim($html) === '') $html = (string)$row->agreement_html;
        return trim($html) !== '' ? self::render_document($html, $row) : '';
    }

    public static function prepare_pdf_html(string $html, string $title=''): string {
        if (!str_contains($html, 'bcs-document-051') && !str_contains($html, 'bcs-agreement') && stripos($title, 'umowa') === false) {
            return $html;
        }

        // Gdy renderer otrzymuje pełny dokument HTML, wyciąga identyfikator zgłoszenia
        // z kontekstu żądania i buduje finalny układ 0.52.
        $registration_id = absint($_GET['registration'] ?? $_POST['registration_id'] ?? 0);
        if ($registration_id) {
            $row = self::row($registration_id);
            if ($row) {
                $fragment = self::render_document($html, $row);
                return '<!doctype html><html lang="pl"><head><meta charset="utf-8"><title>'
                    .esc_html($title).'</title></head><body>'.$fragment.'</body></html>';
            }
        }
        return $html;
    }

    public static function render_agreement_view(): void {
        global $wpdb;
        $agreement_id = absint($_GET['agreement'] ?? 0);
        $token = sanitize_text_field(wp_unslash($_GET['token'] ?? ''));
        $access = $wpdb->get_row($wpdb->prepare(
            "SELECT a.registration_id,a.agreement_number,r.public_token,r.agreement_status
             FROM ".BCS_DB::table('agreements')." a
             JOIN ".BCS_DB::table('registrations')." r ON r.id=a.registration_id
             WHERE a.id=%d LIMIT 1",
            $agreement_id
        ));
        if (!$access || (!current_user_can('manage_options') && !hash_equals((string)$access->public_token, $token))) {
            wp_die(BCS_Template_Engine::get('ui', 'access_denied', 'Brak dostępu.'), 403);
        }

        $stage = in_array((string)$access->agreement_status, ['parent_signed','accepted'], true)
            ? 'signed'
            : 'current';
        $fragment = self::html_for_stage((int)$access->registration_id, $stage);
        if ($fragment === '') wp_die('Dokument umowy nie jest dostępny.', 404);

        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'
            .esc_html((string)$access->agreement_number)
            .'</title></head><body>'.$fragment.'</body></html>';
        exit;
    }

    public static function intercept_agreement_download(): void {
        if (empty($_GET['bcs_document'])) return;
        $document = sanitize_key(wp_unslash($_GET['document'] ?? ''));
        $map = [
            'agreement_draft'=>'draft',
            'agreement_sent'=>'sent',
            'agreement_current'=>'current',
            'agreement_signed'=>'signed',
        ];
        if (!isset($map[$document])) return;

        $registration_id = absint($_GET['registration'] ?? 0);
        $token = sanitize_text_field(wp_unslash($_GET['token'] ?? ''));
        if (!$registration_id || $token === '' || !hash_equals(
            BCS_Documents::document_token($registration_id, $document),
            $token
        )) {
            wp_die('Nieprawidłowy dostęp.', 'Basketmania Camp', ['response'=>403]);
        }

        $row = self::row($registration_id);
        if (!$row || empty($row->agreement_number)) {
            wp_die('Dokument umowy nie jest dostępny.', 'Basketmania Camp', ['response'=>404]);
        }
        if ($document === 'agreement_signed'
            && (string)$row->agreement_status !== 'accepted'
            && !current_user_can('manage_options')) {
            wp_die('Podpisana umowa będzie dostępna po podpisie Organizatora.', 'Basketmania Camp', ['response'=>403]);
        }

        $fragment = self::html_for_stage($registration_id, $map[$document]);
        if ($fragment === '') {
            wp_die('Dokument umowy nie jest dostępny.', 'Basketmania Camp', ['response'=>404]);
        }

        $html = '<!doctype html><html lang="pl"><head><meta charset="utf-8"><title>'
            .esc_html((string)$row->agreement_number)
            .'</title></head><body>'.$fragment.'</body></html>';

        $dir = BCS_Documents::uploads_dir().'/registration-'.$registration_id;
        if (!is_dir($dir)) wp_mkdir_p($dir);
        $name = $document === 'agreement_signed' ? '02-umowa-podpisana.pdf' : '02-umowa.pdf';
        $path = $dir.'/'.$name;

        if (!BCS_PDF::generate($html, $path, 'Umowa '.(string)$row->agreement_number) || !file_exists($path)) {
            wp_die('Nie udało się wygenerować dokumentu PDF.', 'Basketmania Camp', ['response'=>500]);
        }

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="'.basename($path).'"');
        header('Content-Length: '.filesize($path));
        readfile($path);
        exit;
    }
}
